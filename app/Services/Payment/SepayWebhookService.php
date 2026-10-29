<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Support\VietQr;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Modules\Payment\Entities\BankTransaction;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\PartnerBankChannel;
use Modules\Payment\Http\Controllers\PaymentController;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Kênh chuyển khoản ngân hàng của đối tác (VietQR + SePay): khách quét VietQR chuyển thẳng vào tài khoản đối tác với nội dung
 * "365H<mã đơn>", SePay thấy tiền vào và gọi webhook — ở đây khớp mã đơn, kiểm tra số tiền rồi ghi nhận qua đúng các nhánh xử lý
 * của webhook PayOS (PaymentController::applyBankTransfer()).
 *
 * Bảo mật: SePay gửi header "Authorization: Apikey <khoá>". Khoá riêng của 1 kênh CHỈ được ghi nhận tiền vào đúng số tài khoản của
 * kênh đó và cho đơn của chính đối tác đó — không thì đối tác A (biết khoá của mình) tự gửi webhook giả đánh dấu "đã thanh toán"
 * cho đơn của đối tác B. Khoá chung (config sepay.webhook_key, 365home tự quản tài khoản SePay) thì đối tác xác định theo số tài
 * khoản nhận.
 *
 * Khoản nào không tự ghi nhận được (không có mã đơn, chuyển thiếu, đơn đã huỷ/hết hạn...) vẫn được lưu ở bank_transactions để
 * đối soát — không bao giờ tự đổi trạng thái đơn trong các trường hợp đó.
 */
class SepayWebhookService
{
    public const PROVIDER = 'sepay';

    /**
     * Khoá trong header có hợp lệ không. Trả ['channel' => kênh của khoá riêng | null khi là khoá chung], hoặc null nếu sai khoá.
     *
     * @return array{channel: ?PartnerBankChannel}|null
     */
    public function authenticate(?string $authorization): ?array
    {
        if (! preg_match('/^\s*Apikey\s+(\S+)\s*$/i', (string) $authorization, $m)) {
            return null;
        }

        $key = $m[1];
        $global = (string) config('sepay.webhook_key');

        if ($global !== '' && hash_equals($global, $key)) {
            return ['channel' => null];
        }

        $channel = PartnerBankChannel::where('webhook_key_hash', PartnerBankChannel::hashKey($key))->first();

        return $channel ? ['channel' => $channel] : null;
    }

    /** Xử lý 1 webhook đã xác thực. Trả giao dịch đã lưu (hoặc đã có từ lần gửi trước), null nếu không phải tiền vào. */
    public function handle(array $payload, ?PartnerBankChannel $keyChannel): ?BankTransaction
    {
        if (($payload['transferType'] ?? null) !== 'in' || blank($payload['id'] ?? null)) {
            return null;
        }

        $accountNumber = isset($payload['accountNumber']) ? trim((string) $payload['accountNumber']) : null;
        $subAccount    = isset($payload['subAccount']) ? trim((string) $payload['subAccount']) : null;
        $channel       = $this->channelFor($keyChannel, $accountNumber, $subAccount);

        $channel?->forceFill(['last_webhook_at' => now()])->saveQuietly();

        $amount = (int) ($payload['transferAmount'] ?? 0);
        $code   = $this->extractOrderCode($payload);

        try {
            return DB::transaction(function () use ($payload, $channel, $keyChannel, $accountNumber, $amount, $code) {
                // Tạo dòng giao dịch TRƯỚC (unique provider + id) — SePay gửi lại cùng 1 giao dịch thì dừng ở đây, không ghi nhận 2 lần.
                $txn = BankTransaction::create([
                    'provider'        => self::PROVIDER,
                    'provider_txn_id' => (string) $payload['id'],
                    'partner_id'      => $channel?->partner_id,
                    'gateway'         => $payload['gateway'] ?? null,
                    'account_number'  => $accountNumber,
                    'amount'          => $amount,
                    'content'         => $payload['content'] ?? null,
                    'reference_code'  => $payload['referenceCode'] ?? null,
                    'transacted_at'   => $payload['transactionDate'] ?? null,
                    'status'          => BankTransaction::STATUS_UNMATCHED,
                    'payload'         => $payload,
                ]);

                if (! $channel) {
                    return $this->finish($txn, BankTransaction::STATUS_UNMATCHED, $keyChannel
                        ? 'Số tài khoản nhận không khớp kênh của khoá webhook.'
                        : 'Số tài khoản nhận không thuộc kênh chuyển khoản nào.');
                }

                $order = $code ? Order::withoutGlobalScopes()->where('order_code', $code)->lockForUpdate()->first() : null;

                if (! $order) {
                    return $this->finish($txn, BankTransaction::STATUS_UNMATCHED, $code ? "Không có đơn mã {$code}." : 'Nội dung chuyển khoản không có mã đơn.');
                }

                $txn->order_id = $order->id;

                if (PayOsAccountResolver::partnerIdForOrder($order) !== $channel->partner_id) {
                    return $this->finish($txn, BankTransaction::STATUS_UNMATCHED, 'Đơn không thuộc đối tác sở hữu tài khoản nhận.', clearOrder: true);
                }

                if (PayOsAccountResolver::bankChannelForOrder($order)?->id !== $channel->id) {
                    return $this->finish($txn, BankTransaction::STATUS_NEEDS_REVIEW, 'Đơn không thu qua kênh chuyển khoản này (kênh tắt, hợp đồng thu trực tiếp chưa hiệu lực, hoặc chi nhánh dùng PayOS riêng) — kiểm tra rồi xác nhận tay.');
                }

                return $this->apply($txn, $order, $amount);
            });
        } catch (UniqueConstraintViolationException) {
            return BankTransaction::where('provider', self::PROVIDER)->where('provider_txn_id', (string) $payload['id'])->first();
        }
    }

    /**
     * Khoản cần thu tiếp theo của đơn qua chuyển khoản: [applied_as, số tiền], null nếu không còn gì cần thu. Dùng chung cho mã QR
     * hiển thị cho khách và cho bước kiểm tra số tiền khi tiền về — 2 nơi không bao giờ lệch nhau.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function dueFor(Order $order): ?array
    {
        $extra = (int) ($order->extra_charge_amount ?? 0);

        return match (true) {
            $order->status === 'pending' => [BankTransaction::APPLIED_PAYMENT, $order->depositDueAmount()],
            // Cùng công thức với PaymentController::createRemainingPayment().
            $order->status === 'deposit' => [BankTransaction::APPLIED_REMAINING, max(0, (int) $order->full_amount - $order->depositPaidAmount()) + $extra],
            $order->status === 'paid' && $extra > 0 && $order->extra_charge_paid_at === null => [BankTransaction::APPLIED_EXTRA_CHARGE, $extra],
            default => null,
        };
    }

    public static function paymentContent(Order $order): string
    {
        return self::prefix() . $order->order_code;
    }

    // Không bao giờ để tiền tố rỗng (vd config cache cũ chưa có config/sepay.php) — rỗng thì bất kỳ dãy số nào trong nội dung cũng
    // bị coi là mã đơn.
    private static function prefix(): string
    {
        return (string) (config('sepay.payment_prefix') ?: '365H');
    }

    /**
     * Thông tin chuyển khoản cho khoản cần thu tiếp theo của đơn; null nếu đối tác không dùng kênh này hoặc đơn không còn gì cần
     * thu. qr_payload là nội dung mã VietQR (sinh tại server — xem App\Support\VietQr), app tự vẽ mã từ chuỗi này.
     */
    public static function transferInfo(Order $order): ?array
    {
        $channel = PayOsAccountResolver::bankChannelForOrder($order);
        $due     = self::dueFor($order);
        $bank    = $channel?->bank();

        if (! $channel || ! $due || ! $bank || $due[1] <= 0) {
            return null;
        }

        [$appliedAs, $amount] = $due;
        $content = self::paymentContent($order);

        return [
            'order_code'     => (string) $order->order_code,
            'kind'           => $appliedAs,
            'amount'         => $amount,
            'content'        => $content,
            'bank_code'      => $bank['code'],
            'bank_bin'       => $bank['bin'],
            'bank_name'      => $bank['short_name'],
            'account_number' => $channel->account_number,
            'account_holder' => $channel->account_holder,
            'qr_payload'     => VietQr::payload($bank['bin'], $channel->account_number, $amount, $content),
        ];
    }

    /**
     * Link trang chuyển khoản của đơn — có chữ ký và hạn dùng (config sepay.page_ttl_minutes), không đoán được từ mã đơn. Ký theo
     * đường dẫn tương đối (route dùng middleware signed:relative) để không phụ thuộc host/scheme phía sau proxy.
     */
    public static function pageUrl(Order $order): string
    {
        return url(URL::temporarySignedRoute('payment.bank-transfer', now()->addMinutes(self::pageTtl()), ['code' => $order->order_code], absolute: false));
    }

    public static function statusUrl(Order $order): string
    {
        return URL::temporarySignedRoute('payment.bank-transfer.status', now()->addMinutes(self::pageTtl()), ['code' => $order->order_code], absolute: false);
    }

    private static function pageTtl(): int
    {
        return max(5, (int) (config('sepay.page_ttl_minutes') ?: 120));
    }

    /** IP gọi webhook có được phép không — config sepay.allowed_ips rỗng = không giới hạn IP (chỉ dựa vào khoá API). */
    public static function ipAllowed(?string $ip): bool
    {
        $allowed = array_filter(array_map('trim', explode(',', (string) config('sepay.allowed_ips'))));

        return $allowed === [] || IpUtils::checkIp((string) $ip, $allowed);
    }

    /**
     * Xử lý lại 1 giao dịch chưa tự ghi nhận được (vd đã sửa cách đọc mã đơn, hoặc đã bật kênh/hợp đồng) từ đúng dữ liệu SePay đã
     * gửi. Kênh xác định lại theo số tài khoản nhận — cùng cách với khoá chung, vì dữ liệu này đã được xác thực lúc nhận.
     */
    public function replay(BankTransaction $txn): ?BankTransaction
    {
        if ($txn->status === BankTransaction::STATUS_MATCHED || ! is_array($txn->payload)) {
            return $txn;
        }

        return DB::transaction(function () use ($txn) {
            $payload = $txn->payload;
            $txn->delete();

            return $this->handle($payload, null);
        });
    }

    private function channelFor(?PartnerBankChannel $keyChannel, ?string $accountNumber, ?string $subAccount): ?PartnerBankChannel
    {
        if ($keyChannel) {
            return $keyChannel->ownsAccount($accountNumber, $subAccount) ? $keyChannel : null;
        }

        $numbers = array_values(array_filter([$accountNumber, $subAccount]));

        return $numbers ? PartnerBankChannel::whereIn('account_number', $numbers)->where('is_active', true)->first() : null;
    }

    // Mã đơn trong nội dung: ưu tiên trường "code" SePay đã tách sẵn (khi cấu hình mã thanh toán), không có thì tự tìm trong nội
    // dung — chấp nhận ngân hàng chèn khoảng trắng/gạch giữa tiền tố và số ("365H 12345", "365H-12345").
    public function extractOrderCode(array $payload): ?string
    {
        $prefix = preg_quote(self::prefix(), '/');

        foreach ([$payload['code'] ?? null, $payload['content'] ?? null, $payload['description'] ?? null] as $text) {
            if (filled($text) && preg_match('/' . $prefix . '[\s\-_.]?(\d{4,20})(?!\d)/i', (string) $text, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function apply(BankTransaction $txn, Order $order, int $amount): BankTransaction
    {
        $due = self::dueFor($order);

        if (! $due) {
            $note = in_array($order->status, ['failed', 'cancelled_payment'], true)
                ? 'Đơn đã huỷ/hết hạn thanh toán — kiểm tra phòng còn trống rồi xác nhận tay hoặc hoàn tiền.'
                : 'Đơn không còn khoản nào cần thu (đã thanh toán/hoàn tiền).';

            return $this->finish($txn, BankTransaction::STATUS_NEEDS_REVIEW, $note);
        }

        [$appliedAs, $expected] = $due;

        if ($amount < $expected) {
            return $this->finish($txn, BankTransaction::STATUS_AMOUNT_MISMATCH, 'Chuyển thiếu: cần ' . number_format($expected, 0, ',', '.') . 'đ.');
        }

        $controller = app(PaymentController::class);

        if (! $controller->applyBankTransfer($order, $appliedAs)) {
            // Các nhánh xử lý tự bắt lỗi và trả false — ném ra để rollback, trả 500 cho SePay tự gửi lại.
            throw new \RuntimeException("Không ghi nhận được chuyển khoản cho đơn {$order->order_code}.");
        }

        $note = null;

        // Đơn cọc mà khách chuyển luôn đủ cả đơn: ghi nhận tiếp phần còn lại.
        if ($appliedAs === BankTransaction::APPLIED_PAYMENT && $order->fresh()->status === 'deposit') {
            $remaining = self::dueFor($order->fresh());

            if ($remaining && $amount >= $expected + $remaining[1]) {
                $controller->applyBankTransfer($order->fresh(), BankTransaction::APPLIED_REMAINING);
                $expected += $remaining[1];
                $note = 'Khách chuyển đủ cả đơn — đã ghi nhận cả tiền cọc và phần còn lại.';
            }
        }

        if ($amount > $expected) {
            $note = trim(($note ?? '') . ' Chuyển dư ' . number_format($amount - $expected, 0, ',', '.') . 'đ.');
        }

        $txn->applied_as = $appliedAs;

        return $this->finish($txn, BankTransaction::STATUS_MATCHED, $note);
    }

    private function finish(BankTransaction $txn, string $status, ?string $note, bool $clearOrder = false): BankTransaction
    {
        $txn->status = $status;
        $txn->note   = $note;

        if ($clearOrder) {
            $txn->order_id = null;
        }

        $txn->save();

        if ($status !== BankTransaction::STATUS_MATCHED) {
            Log::warning('SePay: giao dịch chưa tự ghi nhận được', ['txn_id' => $txn->provider_txn_id, 'status' => $status, 'note' => $note, 'amount' => $txn->amount]);
        }

        return $txn;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\PartnerEscrowDeposit;
use App\Models\PartnerSettlement;
use App\Models\SubscriptionPayment;
use App\Services\EscrowPayosService;
use App\Services\SettlementService;
use App\Services\SubscriptionService;
use App\Support\Pay2SConfig;
use App\Support\VietQrPayload;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Services\InvoiceContentRenderer;
use Modules\Payment\Entities\PartnerSePayAccount;
use Modules\Payment\Entities\SePayTransaction;
use Modules\VehicleRental\App\Models\VehicleBooking;
use Modules\VehicleRental\App\Services\VehicleBookingService;

/**
 * Các khoản thu KHÔNG phải đơn đặt phòng, qua Pay2S (thay cho link PayOS): phí gói dịch vụ (SB…), nạp ký quỹ (KQ…), nộp hoa hồng đối soát (DS…),
 * thuê xe (XE…) và hoá đơn MiniHouse (MH + mã 9 chữ số).
 *
 * Không gọi API tạo link: mỗi khoản chỉ cần (1) hướng dẫn chuyển khoản — ngân hàng + số tiền + nội dung = mã khoản — kèm chuỗi QR VietQR, lưu vào CHÍNH các cột
 * payos_* cũ (payos_qr_code, payos_bank_bin, payos_account_number...) để app/web đang đọc các cột đó không phải sửa; (2) khi Pay2S báo tiền vào (webhook / kéo API),
 * SePayService::handleIncoming() chuyển các giao dịch không mang mã đơn phòng (DH…) sang handle() ở đây để tìm khoản theo mã trong nội dung chuyển khoản.
 * Mỗi giao dịch ghi 1 dòng sepay_transactions (provider pay2s) → chống xử lý trùng và có chỗ đối soát khi bị từ chối (thiếu tiền, chuyển trùng, khoản đã đóng...).
 */
class PlatformCollectionService
{
    public const KIND_SUBSCRIPTION = 'subscription';

    public const KIND_ESCROW = 'escrow';

    public const KIND_SETTLEMENT = 'settlement';

    public const KIND_VEHICLE = 'vehicle';

    public const KIND_MINIHOUSE = 'minihouse';

    /** Khoản thu độc lập do admin tạo (màn hình "Tạo mã thu tiền"), mã TTxxxxxx. */
    public const KIND_COLLECTION = 'collection';

    /** Tiền tố nội dung chuyển khoản của hoá đơn MiniHouse; mã đi sau là invoices.payos_order_code (9 chữ số, luôn bắt đầu bằng 9). */
    public const MINIHOUSE_PREFIX = 'MH';

    /** Các khoản của 365 đang thu qua Pay2S (đã chọn cổng Pay2S + khai báo tài khoản 365)? */
    public function active(): bool
    {
        return Pay2SConfig::platformAccount() !== null;
    }

    /**
     * Hướng dẫn chuyển khoản vào tài khoản 365 cho 1 khoản; null khi không thu qua Pay2S (nơi gọi quay về PayOS).
     *
     * @return array{bank_bin: string, bank_code: string, account_number: string, account_name: string, transfer_content: string, amount: int, qr_code: string}|null
     */
    public function platformInstruction(int $amount, string $content): ?array
    {
        $account = Pay2SConfig::platformAccount();

        return $account ? $this->instruction((string) $account->bank_bin, (string) $account->bank_code, (string) $account->account_number, (string) $account->account_holder, $amount, $content) : null;
    }

    /** @return array{bank_bin: string, bank_code: string, account_number: string, account_name: string, transfer_content: string, amount: int, qr_code: string} */
    public function instruction(string $bankBin, string $bankCode, string $accountNumber, string $accountHolder, int $amount, string $content): array
    {
        return [
            'bank_bin' => $bankBin,
            'bank_code' => $bankCode,
            'account_number' => $accountNumber,
            'account_name' => $accountHolder,
            'transfer_content' => $content,
            'amount' => $amount,
            'qr_code' => $bankBin !== '' ? VietQrPayload::build($bankBin, $accountNumber, $amount, $content) : '',
        ];
    }

    /**
     * Ghi hướng dẫn chuyển khoản Pay2S vào các cột payos_* của khoản (cùng cột PayOS dùng, nên app/web đọc nguyên như cũ). Trả false khi không thu qua Pay2S
     * (nơi gọi tạo link PayOS như trước). $columns đổi tên cột theo từng bảng; đặt null để bỏ cột không có (vd thuê xe không lưu thông tin ngân hàng).
     *
     * @param array<string, ?string> $columns qr | expires | bank_bin | account | holder | checkout | link_id
     */
    public function attach(string $kind, \Illuminate\Database\Eloquent\Model $model, int $amount, string $content, \Carbon\Carbon $expiresAt, array $columns = []): bool
    {
        $instruction = $this->platformInstruction($amount, $content);

        if (! $instruction) {
            return false;
        }

        $map = $columns + ['qr' => 'payos_qr_code', 'expires' => 'payos_expired_at', 'bank_bin' => 'payos_bank_bin', 'account' => 'payos_account_number',
            'holder' => 'payos_account_name', 'checkout' => 'payos_checkout_url', 'link_id' => 'payos_payment_link_id'];
        $values = ['qr' => $instruction['qr_code'], 'expires' => $expiresAt, 'bank_bin' => $instruction['bank_bin'], 'account' => $instruction['account_number'],
            'holder' => $instruction['account_name'], 'checkout' => $this->publicUrl($kind, $content), 'link_id' => null];

        $model->update(collect($map)->filter()->mapWithKeys(fn (string $column, string $key) => [$column => $values[$key]])->all());

        return true;
    }

    /** Trang QR công khai của 1 khoản (thay cho trang checkout của PayOS): hiện QR + thông tin chuyển khoản và tự báo khi đã nhận tiền. */
    public function publicUrl(string $kind, string $code): string
    {
        return route('platform-collect.show', ['kind' => $kind, 'code' => $code]);
    }

    /**
     * Thông tin hiển thị của 1 khoản theo loại + mã nội dung chuyển khoản (trang QR công khai); null nếu không có.
     *
     * @return array{title: string, code: string, amount: int, status: string, expires_at: ?string, qr_code: string, bank: array{code: string, bin: string, account_number: string, account_holder: string}}|null
     */
    public function find(string $kind, string $code): ?array
    {
        $code = Str::upper(trim($code));
        $bank = null;
        $expires = null;

        switch ($kind) {
            case self::KIND_SUBSCRIPTION:
                $m = SubscriptionPayment::query()->where('transaction_code', $code)->first();
                $title = 'Phí gói dịch vụ';
                $amount = (int) ($m?->amount_vnd ?? 0);
                $status = ! $m ? null : ($m->status === SubscriptionPayment::STATUS_PAID ? 'paid' : (in_array($m->status, [SubscriptionPayment::STATUS_EXPIRED, SubscriptionPayment::STATUS_CANCELLED], true) ? 'closed' : 'pending'));
                $expires = $m?->payos_expired_at;
                break;
            case self::KIND_ESCROW:
                $m = PartnerEscrowDeposit::query()->where('transaction_code', $code)->first();
                $title = 'Nạp ký quỹ';
                $amount = (int) ($m?->amount ?? 0);
                $status = ! $m ? null : ($m->status === PartnerEscrowDeposit::STATUS_PAID ? 'paid' : (in_array($m->status, [PartnerEscrowDeposit::STATUS_EXPIRED, PartnerEscrowDeposit::STATUS_CANCELLED], true) ? 'closed' : 'pending'));
                $expires = $m?->payos_expired_at;
                break;
            case self::KIND_SETTLEMENT:
                $m = PartnerSettlement::query()->where('code', $code)->first();
                $title = 'Nộp hoa hồng đối soát';
                $amount = (int) ($m?->net_amount ?? 0);
                $status = ! $m ? null : ($m->isFinal() ? 'paid' : 'pending');
                $expires = $m?->payos_expired_at;
                break;
            case self::KIND_VEHICLE:
                $m = VehicleBooking::query()->where('code', $code)->first();
                $title = 'Thuê xe';
                $amount = (int) ($m?->total_amount ?? 0);
                $status = ! $m ? null : ($m->paid_at !== null ? 'paid' : ($m->status === VehicleBooking::STATUS_CANCELLED ? 'closed' : 'pending'));
                $expires = $m?->payos_expires_at;
                break;
            case self::KIND_COLLECTION:
                $m = \Modules\Payment\Entities\PaymentCollection::query()->where('code', $code)->first();
                $title = $m && filled($m->description) ? (string) $m->description : 'Thanh toán';
                $amount = (int) ($m?->amount ?? 0);
                $status = ! $m ? null : ($m->status === \Modules\Payment\Entities\PaymentCollection::PAID ? 'paid' : ($m->status === \Modules\Payment\Entities\PaymentCollection::CANCELLED || $m->isExpired() ? 'closed' : 'pending'));
                $expires = $m?->expires_at;
                // QR theo tài khoản đã CHỤP lúc tạo, không theo cấu hình hiện tại.
                $bank = $m ? ['code' => (string) $m->bank_code, 'bin' => (string) $m->bank_bin, 'account_number' => (string) $m->account_number, 'account_holder' => (string) $m->account_holder] : null;
                break;
            case self::KIND_MINIHOUSE:
                $m = preg_match('/^' . self::MINIHOUSE_PREFIX . '(9\d{8})$/', $code, $x) ? Invoice::withoutGlobalScopes()->where('payos_order_code', $x[1])->first() : null;
                $title = 'Hoá đơn tiền phòng';
                $amount = $m ? (int) round(InvoiceContentRenderer::totalOwed($m)) : 0;
                $status = ! $m ? null : ($m->status === Invoice::STATUS_PAID ? 'paid' : 'pending');
                $expires = $m?->payos_expired_at;
                $building = $m ? $this->buildingOfInvoice($m) : null;
                $bank = $building && $building->activePaymentMethod() === Building::PAYMENT_METHOD_PAY2S
                    ? ['code' => (string) $building->owner_bank_name, 'bin' => (string) $building->owner_bank_bin, 'account_number' => (string) $building->owner_bank_account_number, 'account_holder' => (string) $building->owner_bank_account_holder]
                    : null;
                break;
            default:
                return null;
        }

        if (! $m || $status === null) {
            return null;
        }

        if (! $bank) {
            $platform = Pay2SConfig::platformAccount(false);
            $bank = $platform ? ['code' => (string) $platform->bank_code, 'bin' => (string) $platform->bank_bin, 'account_number' => (string) $platform->account_number, 'account_holder' => (string) $platform->account_holder] : null;
        }

        if (! $bank) {
            return null;
        }

        return [
            'title' => $title, 'code' => $code, 'amount' => $amount, 'status' => $status,
            'expires_at' => $expires?->toIso8601String(),
            'qr_code' => $bank['bin'] !== '' ? VietQrPayload::build($bank['bin'], $bank['account_number'], $amount, $code) : '',
            'bank' => $bank,
        ];
    }

    private function buildingOfInvoice(Invoice $invoice): ?Building
    {
        return $invoice->contract_id ? \Modules\Minihouse\App\Models\Contract::withoutGlobalScopes()
            ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes()])
            ->find($invoice->contract_id)?->room?->building : null;
    }

    /**
     * Xử lý 1 giao dịch tiền vào mà SePayService không khớp với đơn đặt phòng. Trả null nếu nội dung không mang mã khoản nào của hệ thống
     * (nơi gọi xử lý tiếp như trước: bỏ qua / ghi từ chối).
     *
     * @param array{id:int|string, accountNumber:string, transferAmount:int|string, transactionDate?:?string, referenceCode?:?string, content?:?string, subAccount?:?string} $payload
     * @param array<string,mixed> $raw
     * @return array{status: string, reason: ?string, order: null}|null
     */
    public function handle(array $payload, array $raw, ?PartnerSePayAccount $account): ?array
    {
        $content = Str::upper((string) ($payload['content'] ?? ''));
        $numbers = array_values(array_filter([trim((string) ($payload['subAccount'] ?? '')), trim((string) ($payload['accountNumber'] ?? ''))]));
        $isPlatform = (bool) $account?->isPlatform();

        $match = $this->detect($content, $isPlatform, $numbers);

        if (! $match) {
            return null;
        }

        $amount = (int) $payload['transferAmount'];

        try {
            $transaction = SePayTransaction::query()->create([
                'provider' => PartnerSePayAccount::PROVIDER_PAY2S, 'sepay_id' => $payload['id'], 'partner_sepay_account_id' => null, 'order_id' => null,
                'payment_code' => $match['code'], 'amount' => $amount, 'reference_code' => $payload['referenceCode'] ?? null,
                'transaction_at' => $payload['transactionDate'] ?? null, 'status' => 'received', 'failure_reason' => null, 'payload' => $raw + ['platform_kind' => $match['kind']],
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062 && ! str_contains(strtolower($e->getMessage()), 'unique')) {
                throw $e;
            }

            $transaction = SePayTransaction::query()->where('provider', PartnerSePayAccount::PROVIDER_PAY2S)->where('sepay_id', $payload['id'])->first();

            // Đã xử lý / đã từ chối: gửi lại hoặc kéo lại cùng giao dịch thì bỏ qua; chỉ giao dịch lỗi xử lý mới chạy lại.
            if (! $transaction || $transaction->status !== 'failed') {
                return ['status' => 'duplicate', 'reason' => null, 'order' => null];
            }
        }

        try {
            $reason = $this->apply($match, $amount, 'PAY2S-' . $payload['id']);
        } catch (\Throwable $e) {
            $transaction->update(['status' => 'failed', 'failure_reason' => Str::limit($e->getMessage(), 480, '')]);
            Log::error('Pay2S khoản thu nền tảng: xử lý lỗi', ['kind' => $match['kind'], 'code' => $match['code'], 'error' => $e->getMessage()]);

            return ['status' => 'failed', 'reason' => $e->getMessage(), 'order' => null];
        }

        $transaction->update($reason ? ['status' => 'rejected', 'failure_reason' => $reason] : ['status' => 'processed', 'failure_reason' => null]);

        return $reason
            ? ['status' => 'rejected', 'reason' => $reason, 'order' => null]
            : ['status' => 'processed', 'reason' => null, 'order' => null];
    }

    /** Nội dung chuyển khoản có chứa mã khoản nào không (chỉ tra mã, chưa đụng dữ liệu). @return array{kind: string, code: string}|null */
    public function detect(string $content, bool $isPlatformAccount, array $accountNumbers = []): ?array
    {
        // Hoá đơn MiniHouse: vào tài khoản 365 (toà chưa có tài khoản riêng) hoặc tài khoản ngân hàng riêng của chủ toà (kiểm tra số tài khoản ở apply()).
        if (preg_match('/' . self::MINIHOUSE_PREFIX . '(9\d{8})/', $content, $m)) {
            return ['kind' => self::KIND_MINIHOUSE, 'code' => $m[1], 'numbers' => $accountNumbers, 'platform' => $isPlatformAccount];
        }

        if (! $isPlatformAccount) {
            return null;
        }

        $squashed = str_replace([' ', '-'], '', $content);

        return match (true) {
            (bool) preg_match('/SB[A-HJ-NP-Z2-9]{6}/', $content, $m) => ['kind' => self::KIND_SUBSCRIPTION, 'code' => $m[0]],
            (bool) preg_match('/KQ[A-HJ-NP-Z2-9]{6}/', $content, $m) => ['kind' => self::KIND_ESCROW, 'code' => $m[0]],
            (bool) preg_match('/DS\d{4}[A-Z0-9]{5}/', $squashed, $m) => ['kind' => self::KIND_SETTLEMENT, 'code' => substr($m[0], 0, 6) . '-' . substr($m[0], 6)],
            (bool) preg_match('/XE\d{6}[A-Z0-9]{4}/', $squashed, $m) => ['kind' => self::KIND_VEHICLE, 'code' => $m[0]],
            (bool) preg_match('/TT[A-HJ-NP-Z2-9]{6}/', $content, $m) => ['kind' => self::KIND_COLLECTION, 'code' => $m[0]],
            default => null,
        };
    }

    /** @return string|null lý do từ chối (null = đã ghi nhận khoản) */
    private function apply(array $match, int $amount, string $reference): ?string
    {
        return match ($match['kind']) {
            self::KIND_SUBSCRIPTION => $this->applySubscription($match['code'], $amount, $reference),
            self::KIND_ESCROW => $this->applyEscrow($match['code'], $amount, $reference),
            self::KIND_SETTLEMENT => $this->applySettlement($match['code'], $amount, $reference),
            self::KIND_VEHICLE => $this->applyVehicle($match['code'], $amount),
            self::KIND_COLLECTION => \Illuminate\Support\Facades\DB::transaction(fn () => app(PaymentCollectionService::class)->markPaid($match['code'], $amount, $reference)),
            self::KIND_MINIHOUSE => $this->applyMinihouse((int) $match['code'], $amount, $reference, (array) ($match['numbers'] ?? []), (bool) ($match['platform'] ?? false)),
        };
    }

    private function applySubscription(string $code, int $amount, string $reference): ?string
    {
        $payment = SubscriptionPayment::query()->where('transaction_code', $code)->first();

        if (! $payment) {
            return "Không tìm thấy giao dịch gói dịch vụ {$code}.";
        }
        if ($payment->status === SubscriptionPayment::STATUS_PAID) {
            return "Giao dịch gói {$code} đã được thanh toán trước đó (chuyển trùng) — cần hoàn tiền thủ công.";
        }

        if (app(SubscriptionService::class)->markPaid($payment, $amount, $reference) === null) {
            return $amount < (int) $payment->amount_vnd
                ? "Thiếu tiền gói {$code}: cần {$payment->amount_vnd}, nhận {$amount} — chưa kích hoạt, xử lý thủ công."
                : "Không ghi nhận được giao dịch gói {$code} (đã xử lý hoặc trùng mã giao dịch ngân hàng).";
        }

        return null;
    }

    private function applyEscrow(string $code, int $amount, string $reference): ?string
    {
        $deposit = PartnerEscrowDeposit::query()->where('transaction_code', $code)->first();

        if (! $deposit) {
            return "Không tìm thấy yêu cầu nạp ký quỹ {$code}.";
        }
        if ($deposit->status === PartnerEscrowDeposit::STATUS_PAID) {
            return "Yêu cầu nạp ký quỹ {$code} đã được ghi nhận trước đó (chuyển trùng) — cần hoàn tiền thủ công.";
        }

        // Cộng số dư theo SỐ TIỀN THỰC NHẬN (như luồng PayOS).
        return app(EscrowPayosService::class)->markPaid($deposit, $amount, $reference) ? null : "Không cộng được ký quỹ cho yêu cầu {$code}.";
    }

    private function applySettlement(string $code, int $amount, string $reference): ?string
    {
        $settlement = PartnerSettlement::query()->where('code', $code)->first();

        if (! $settlement) {
            return "Không tìm thấy bảng đối soát {$code}.";
        }
        if ($amount < (int) $settlement->net_amount) {
            return "Thiếu tiền bảng đối soát {$code}: cần {$settlement->net_amount}, nhận {$amount} — chưa ghi nhận, xử lý thủ công.";
        }

        return app(SettlementService::class)->markPaid($settlement, $reference) ? null : "Bảng đối soát {$code} đã chốt trước đó (chuyển trùng) — cần hoàn tiền thủ công.";
    }

    private function applyVehicle(string $code, int $amount): ?string
    {
        $booking = VehicleBooking::query()->where('code', $code)->first();

        if (! $booking) {
            return "Không tìm thấy đơn thuê xe {$code}.";
        }
        if ($booking->paid_at !== null) {
            return "Đơn thuê xe {$code} đã thanh toán trước đó (chuyển trùng) — cần hoàn tiền thủ công.";
        }
        if ($amount < (int) $booking->total_amount) {
            return "Thiếu tiền đơn thuê xe {$code}: cần {$booking->total_amount}, nhận {$amount} — chưa xác nhận, xử lý thủ công.";
        }

        app(VehicleBookingService::class)->markPaid($booking, $amount);

        return null;
    }

    /**
     * Hoá đơn MiniHouse. Tiền vào tài khoản 365 → hoá đơn của toà chưa có Pay2S riêng; tiền vào số tài khoản ngân hàng của chủ toà → toà phải đang chọn Pay2S
     * và số tài khoản phải đúng của toà đó (không để tiền vào tài khoản toà A xác nhận hoá đơn toà B).
     *
     * @param string[] $accountNumbers
     */
    private function applyMinihouse(int $code, int $amount, string $reference, array $accountNumbers, bool $isPlatformAccount): ?string
    {
        $invoice = Invoice::withoutGlobalScopes()->where('payos_order_code', $code)->first();

        if (! $invoice) {
            return 'Không tìm thấy hoá đơn MiniHouse theo mã ' . self::MINIHOUSE_PREFIX . $code . '.';
        }

        $building = $this->buildingOfInvoice($invoice);

        if ($isPlatformAccount) {
            if ($building && $building->activePaymentMethod() === Building::PAYMENT_METHOD_PAY2S) {
                return 'Toà nhà đang thu qua Pay2S riêng nhưng tiền vào tài khoản 365home — đối soát/hoàn tiền thủ công.';
            }
        } elseif (! $building || $building->activePaymentMethod() !== Building::PAYMENT_METHOD_PAY2S
            || ! in_array(trim((string) $building->owner_bank_account_number), $accountNumbers, true)) {
            return 'Số tài khoản nhận tiền không phải tài khoản Pay2S của toà nhà có hoá đơn này — đối soát thủ công.';
        }

        $owed = InvoiceContentRenderer::totalOwed($invoice);

        if ($invoice->status !== Invoice::STATUS_PAID && abs($amount - $owed) > 1) {
            return "Số tiền không khớp hoá đơn; cần {$owed}, nhận {$amount} — đối soát thủ công.";
        }

        \App\Http\Controllers\Api\Minihouse\PayOsWebhookController::processVerifiedData(
            ['orderCode' => $invoice->payos_order_code, 'code' => '00', 'amount' => $amount, 'reference' => $reference],
            'Pay2S',
        );

        return null;
    }
}

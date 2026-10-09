<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Partner;
use App\Models\PartnerEscrowDeposit as Deposit;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PayOS\PayOS;

// NẠP KÝ QUỸ qua PayOS — dùng tài khoản/kênh PayOS của CÔNG TY (config payos.*, tiền ký quỹ về 365home chứ không về
// kênh của đối tác) và CHUNG webhook POST /webhook/payos, cùng cách với SubscriptionPayosService: PaymentController gọi
// handleWebhook() sau khi xác thực chữ ký; không phải giao dịch ký quỹ thì trả null để luồng khác chạy tiếp.
class EscrowPayosService
{
    // Dải mã đơn riêng (bắt đầu bằng 9) để không đụng đơn đặt phòng / gói dịch vụ (8...) trên cùng tài khoản PayOS.
    private const ORDER_CODE_PREFIX = '9';

    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private EscrowService $escrow) {}

    public function isConfigured(): bool
    {
        return filled(Config::get('payos.client_id')) && filled(Config::get('payos.api_key')) && filled(Config::get('payos.checksum_key'));
    }

    protected function client(): PayOS
    {
        return new PayOS((string) Config::get('payos.client_id'), (string) Config::get('payos.api_key'), (string) Config::get('payos.checksum_key'));
    }

    /**
     * Tạo yêu cầu nạp + link/QR PayOS. Lỗi PayOS không làm hỏng yêu cầu: vẫn có mã giao dịch (KQxxxxxx) để chuyển khoản
     * tay rồi Super Admin ghi nhận.
     */
    public function createDeposit(Partner $partner, int $amount, ?User $by): Deposit
    {
        $deposit = Deposit::create([
            'partner_id' => $partner->id, 'transaction_code' => $this->newTransactionCode(), 'amount' => $amount,
            'status' => Deposit::STATUS_PENDING, 'created_by' => $by?->id,
        ]);

        if (! $this->isConfigured()) {
            return $deposit;
        }

        try {
            $orderCode = $this->newOrderCode();
            $expiredAt = now()->addHours((int) config('escrow.deposit_link_hours', 24));
            $home = rtrim((string) config('app.url'), '/') . '/';

            $link = $this->client()->createPaymentLink([
                'orderCode'   => $orderCode,
                'amount'      => $amount,
                'description' => $deposit->transaction_code,
                'returnUrl'   => $home,
                'cancelUrl'   => $home,
                'buyerName'   => (string) ($partner->legal_name ?: $partner->name),
                'items'       => [['name' => 'Nap ky quy doi tac', 'quantity' => 1, 'price' => $amount]],
                'expiredAt'   => $expiredAt->timestamp,
            ]);

            $deposit->update([
                'payos_order_code'      => $orderCode,
                'payos_payment_link_id' => $link['paymentLinkId'] ?? null,
                'payos_checkout_url'    => $link['checkoutUrl'] ?? null,
                'payos_qr_code'         => $link['qrCode'] ?? null,
                'payos_expired_at'      => $expiredAt,
                'payos_bank_bin'        => $link['bin'] ?? null,
                'payos_account_number'  => $link['accountNumber'] ?? null,
                'payos_account_name'    => $link['accountName'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Ký quỹ: không tạo được link PayOS', ['deposit' => $deposit->id, 'error' => $e->getMessage()]);
        }

        return $deposit->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload  body webhook (chữ ký đã được kiểm tra bằng key PayOS chung)
     * @return array<string, mixed>|null  phản hồi nếu là giao dịch ký quỹ; null nếu không phải
     */
    public function handleWebhook(array $payload): ?array
    {
        $data = $payload['data'] ?? [];

        if (! is_array($data) || ! isset($data['orderCode'])) {
            return null;
        }

        $deposit = Deposit::query()->where('payos_order_code', (int) $data['orderCode'])->first();

        // Chuyển khoản trực tiếp có nội dung chứa mã giao dịch (KQxxxxxx).
        if (! $deposit && preg_match('/KQ[A-HJ-NP-Z2-9]{6}/', strtoupper((string) ($data['description'] ?? '')), $m)) {
            $deposit = Deposit::query()->where('transaction_code', $m[0])->first();
        }

        if (! $deposit) {
            return null;
        }

        if ((string) ($data['code'] ?? $payload['code'] ?? '') === '00') {
            $this->markPaid($deposit, (int) ($data['amount'] ?? 0), (string) ($data['reference'] ?? '') ?: 'PAYOS-' . $data['orderCode']);
        }

        return ['error' => 0, 'message' => 'Escrow webhook processed'];
    }

    /** Cộng số dư theo SỐ TIỀN THỰC NHẬN (webhook gọi lặp không cộng hai lần: mỗi yêu cầu nạp chỉ sinh một bút toán). */
    public function markPaid(Deposit $deposit, int $paidAmount, ?string $reference): bool
    {
        $amount = $paidAmount > 0 ? $paidAmount : (int) $deposit->amount;

        $credited = DB::transaction(function () use ($deposit, $amount, $reference) {
            $locked = Deposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Deposit::STATUS_PAID) {
                return false;
            }

            $locked->update(['status' => Deposit::STATUS_PAID, 'paid_at' => now(), 'bank_reference' => $reference]);
            $this->escrow->deposit($locked->partner, $amount, [
                'reason' => 'Nạp ký quỹ qua PayOS (mã GD ' . $locked->transaction_code . ').', 'reference' => $reference, 'deposit_id' => $locked->id,
            ]);

            return true;
        });

        if ($credited) {
            $this->escrow->notifyDeposited($deposit->partner, $amount);
        }

        return $credited;
    }

    /** Đóng các yêu cầu nạp đã quá hạn link mà chưa thanh toán. Chạy định kỳ (escrow:process). */
    public function expireStaleDeposits(): int
    {
        return Deposit::query()->where('status', Deposit::STATUS_PENDING)
            ->where(fn ($q) => $q->where('payos_expired_at', '<', now())->orWhere(fn ($q) => $q->whereNull('payos_expired_at')->where('created_at', '<', now()->subDays(7))))
            ->update(['status' => Deposit::STATUS_EXPIRED]);
    }

    private function newOrderCode(): int
    {
        do {
            $code = (int) (self::ORDER_CODE_PREFIX . substr((string) (int) (microtime(true) * 1000), -13));
        } while (Deposit::query()->where('payos_order_code', $code)->exists());

        return $code;
    }

    private function newTransactionCode(): string
    {
        do {
            $code = 'KQ';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (Deposit::query()->where('transaction_code', $code)->exists());

        return $code;
    }
}

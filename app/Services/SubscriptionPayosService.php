<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SubscriptionPayment as Pay;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PayOS\PayOS;

// Thu PHÍ GÓI DỊCH VỤ qua PayOS — dùng ĐÚNG tài khoản/kênh PayOS của công ty (config payos.*) và CHUNG webhook POST /webhook/payos:
// PaymentController gọi handleWebhook() trước khi xử lý đơn đặt phòng; không phải giao dịch gói thì trả null để luồng khác chạy tiếp.
// Thanh toán thành công → SubscriptionService::markPaid() tự gia hạn/kích hoạt.
class SubscriptionPayosService
{
    // Dải mã đơn riêng (bắt đầu bằng 8) để không đụng đơn đặt phòng / ký quỹ (9...) trên cùng tài khoản PayOS.
    private const ORDER_CODE_PREFIX = '8';

    public function isConfigured(): bool
    {
        return filled(Config::get('payos.client_id')) && filled(Config::get('payos.api_key')) && filled(Config::get('payos.checksum_key'));
    }

    protected function client(): PayOS
    {
        return new PayOS((string) Config::get('payos.client_id'), (string) Config::get('payos.api_key'), (string) Config::get('payos.checksum_key'));
    }

    /** Tạo link/QR PayOS. Lỗi PayOS không làm hỏng yêu cầu (vẫn có mã giao dịch để chuyển khoản tay & Super Admin xác nhận). */
    public function createLink(Pay $payment): void
    {
        if (! $this->isConfigured() || $payment->payos_order_code) {
            return;
        }

        try {
            $partner = $payment->partner;
            $orderCode = $this->newOrderCode();
            $expiredAt = now()->addHours((int) config('subscription.payment_link_hours', 24));

            $link = $this->client()->createPaymentLink([
                'orderCode'   => $orderCode,
                'amount'      => (int) $payment->amount_vnd,
                'description' => (string) $payment->transaction_code,
                'returnUrl'   => rtrim((string) config('app.url'), '/') . '/',
                'cancelUrl'   => rtrim((string) config('app.url'), '/') . '/',
                'buyerName'   => (string) ($partner?->legal_name ?: $partner?->name),
                'items'       => [['name' => 'Phi goi dich vu ' . $payment->months . ' thang', 'quantity' => 1, 'price' => (int) $payment->amount_vnd]],
                'expiredAt'   => $expiredAt->timestamp,
            ]);

            $payment->update([
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
            Log::warning('Gói dịch vụ: không tạo được link PayOS', ['payment' => $payment->id, 'error' => $e->getMessage()]);
        }
    }

    public function cancelLink(Pay $payment): void
    {
        if (! $this->isConfigured() || ! $payment->payos_order_code) {
            return;
        }

        try {
            $this->client()->cancelPaymentLink($payment->payos_order_code, 'Yeu cau thanh toan da dong');
        } catch (\Throwable $e) {
            Log::warning('Gói dịch vụ: không huỷ được link PayOS', ['payment' => $payment->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload  body webhook (chữ ký đã được kiểm tra)
     * @return array<string, mixed>|null  phản hồi nếu là giao dịch gói; null nếu không phải
     */
    public function handleWebhook(array $payload): ?array
    {
        $data = $payload['data'] ?? [];

        if (! is_array($data) || ! isset($data['orderCode'])) {
            return null;
        }

        $payment = Pay::query()->where('payos_order_code', (int) $data['orderCode'])->first();

        // Chuyển khoản trực tiếp có nội dung chứa mã giao dịch (SBxxxxxx).
        if (! $payment && preg_match('/SB[A-HJ-NP-Z2-9]{6}/', strtoupper((string) ($data['description'] ?? '')), $m)) {
            $payment = Pay::query()->where('transaction_code', $m[0])->first();
        }

        if (! $payment) {
            return null;
        }

        if ((string) ($data['code'] ?? $payload['code'] ?? '') === '00') {
            app(SubscriptionService::class)->markPaid($payment, (int) ($data['amount'] ?? 0), (string) ($data['reference'] ?? '') ?: 'PAYOS-' . $data['orderCode']);
        }

        return ['error' => 0, 'message' => 'Subscription webhook processed'];
    }

    private function newOrderCode(): int
    {
        do {
            $code = (int) (self::ORDER_CODE_PREFIX . substr((string) (int) (microtime(true) * 1000), -13));
        } while (Pay::query()->where('payos_order_code', $code)->exists());

        return $code;
    }
}

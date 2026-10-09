<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PartnerSettlement as Settlement;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PayOS\PayOS;

// QR NỘP HOA HỒNG kỳ đối soát qua PayOS — dùng tài khoản/kênh PayOS của CÔNG TY (config payos.*, tiền về 365home chứ
// không về kênh của đối tác) và CHUNG webhook POST /webhook/payos, cùng cách với EscrowPayosService: PaymentController
// gọi handleWebhook() sau khi xác thực chữ ký; không phải giao dịch đối soát thì trả null để luồng khác chạy tiếp.
class SettlementPayosService
{
    // Dải mã đơn riêng (bắt đầu bằng 6) để không đụng đơn đặt phòng, kênh thử (7...), gói dịch vụ (8...), ký quỹ (9...).
    private const ORDER_CODE_PREFIX = '6';

    public function isConfigured(): bool
    {
        return filled(Config::get('payos.client_id')) && filled(Config::get('payos.api_key')) && filled(Config::get('payos.checksum_key'));
    }

    protected function client(): PayOS
    {
        return new PayOS((string) Config::get('payos.client_id'), (string) Config::get('payos.api_key'), (string) Config::get('payos.checksum_key'));
    }

    /** Tạo link/QR nộp phần đối tác phải nộp. Lỗi PayOS không làm hỏng bảng: đối tác vẫn chuyển khoản tay theo mã bảng. */
    public function createLink(Settlement $settlement): Settlement
    {
        $amount = (int) $settlement->net_amount;

        if ($amount < 2000 || ! $this->isConfigured()) {
            return $settlement;
        }

        try {
            $orderCode = $this->newOrderCode();
            $expiredAt = now()->addHours((int) config('settlement.payment_link_hours', 24));
            $home = rtrim((string) config('app.url'), '/') . '/';
            $partner = $settlement->partner;

            $link = $this->client()->createPaymentLink([
                'orderCode'   => $orderCode,
                'amount'      => $amount,
                'description' => $settlement->code,
                'returnUrl'   => $home,
                'cancelUrl'   => $home,
                'buyerName'   => (string) ($partner->legal_name ?: $partner->name),
                'items'       => [['name' => 'Hoa hong doi soat ' . $settlement->code, 'quantity' => 1, 'price' => $amount]],
                'expiredAt'   => $expiredAt->timestamp,
            ]);

            $settlement->update([
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
            Log::warning('Đối soát: không tạo được link PayOS', ['settlement' => $settlement->id, 'error' => $e->getMessage()]);
        }

        return $settlement->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload  body webhook (chữ ký đã được kiểm tra bằng key PayOS chung)
     * @return array<string, mixed>|null  phản hồi nếu là giao dịch đối soát; null nếu không phải
     */
    public function handleWebhook(array $payload): ?array
    {
        $data = $payload['data'] ?? [];

        if (! is_array($data) || ! isset($data['orderCode'])) {
            return null;
        }

        $settlement = Settlement::query()->where('payos_order_code', (int) $data['orderCode'])->first();

        // Chuyển khoản trực tiếp có nội dung chứa mã bảng đối soát (DSyymm-XXXXX).
        if (! $settlement && preg_match('/DS\d{4}-?[A-Z0-9]{5}/', strtoupper((string) ($data['description'] ?? '')), $m)) {
            $code = strlen($m[0]) === 11 ? substr($m[0], 0, 6) . '-' . substr($m[0], 6) : $m[0];
            $settlement = Settlement::query()->where('code', $code)->first();
        }

        if (! $settlement) {
            return null;
        }

        if ((string) ($data['code'] ?? $payload['code'] ?? '') === '00' && (int) ($data['amount'] ?? 0) >= (int) $settlement->net_amount) {
            app(SettlementService::class)->markPaid($settlement, (string) ($data['reference'] ?? '') ?: 'PAYOS-' . $data['orderCode']);
        }

        return ['error' => 0, 'message' => 'Settlement webhook processed'];
    }

    private function newOrderCode(): int
    {
        do {
            $code = (int) (self::ORDER_CODE_PREFIX . substr((string) (int) (microtime(true) * 1000), -13));
        } while (Settlement::query()->where('payos_order_code', $code)->exists());

        return $code;
    }
}

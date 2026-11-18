<?php

namespace Modules\VehicleRental\App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Modules\VehicleRental\App\Models\VehicleBooking;
use PayOS\PayOS;

/**
 * QR thanh toán tiền thuê xe qua PayOS — dùng tài khoản PayOS CHUNG của 365 (config payos.*, tiền về 365) và CHUNG
 * webhook POST /webhook/payos, cùng cách SettlementPayosService: PaymentController gọi handleWebhook() sau khi xác thực
 * chữ ký; không phải đơn thuê xe thì trả null để luồng khác chạy tiếp.
 */
class VehiclePayOsService
{
    // Dải mã đơn riêng bắt đầu bằng 5: đơn phòng 6-8 chữ số, đối soát (6...), kênh thử (7...), gói dịch vụ (8...), ký quỹ/MiniHouse (9...).
    private const ORDER_CODE_PREFIX = '5';

    public function __construct(private VehicleBookingService $bookings)
    {
    }

    public function isConfigured(): bool
    {
        return filled(Config::get('payos.client_id')) && filled(Config::get('payos.api_key')) && filled(Config::get('payos.checksum_key'));
    }

    protected function client(): PayOS
    {
        return new PayOS((string) Config::get('payos.client_id'), (string) Config::get('payos.api_key'), (string) Config::get('payos.checksum_key'));
    }

    /**
     * Tạo (hoặc tạo lại) link thanh toán cho đơn chờ thanh toán. Link hết hạn cùng lúc giữ chỗ.
     *
     * @throws \RuntimeException khi chưa cấu hình PayOS hoặc PayOS lỗi
     */
    public function createLink(VehicleBooking $booking): VehicleBooking
    {
        if ($booking->status !== VehicleBooking::STATUS_PENDING_PAYMENT || $booking->paid_at !== null) {
            throw new \RuntimeException('Đơn không ở trạng thái chờ thanh toán.');
        }
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Chưa cấu hình cổng thanh toán.');
        }

        $amount = (int) $booking->total_amount;
        if ($amount < 2000) {
            throw new \RuntimeException('Số tiền thanh toán quá nhỏ.');
        }

        // Còn link chưa hết hạn thì dùng lại, tránh tạo nhiều QR cho cùng một đơn.
        if ($booking->payos_checkout_url && $booking->payos_expires_at?->isFuture()) {
            return $booking;
        }

        try {
            $expiresAt = $booking->hold_expires_at && $booking->hold_expires_at->isFuture()
                ? $booking->hold_expires_at
                : now()->addMinutes((int) config('vehiclerental.hold_minutes', 15));
            $orderCode = $this->newOrderCode();
            $home = rtrim((string) config('app.url'), '/') . '/';

            $link = $this->client()->createPaymentLink([
                'orderCode'   => $orderCode,
                'amount'      => $amount,
                'description' => $booking->code,
                'returnUrl'   => $home,
                'cancelUrl'   => $home,
                'items'       => [['name' => 'Thue xe ' . $booking->code, 'quantity' => 1, 'price' => $amount]],
                'expiredAt'   => $expiresAt->timestamp,
            ]);

            $booking->update([
                'payos_order_code'   => $orderCode,
                'payos_checkout_url' => $link['checkoutUrl'] ?? null,
                'payos_qr_code'      => $link['qrCode'] ?? null,
                'payos_expires_at'   => $expiresAt,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Thuê xe: không tạo được link PayOS', ['booking' => $booking->id, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Không tạo được mã thanh toán, vui lòng thử lại.');
        }

        return $booking->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload  body webhook (chữ ký đã được kiểm tra bằng key PayOS chung)
     * @return array<string, mixed>|null  phản hồi nếu là đơn thuê xe; null nếu không phải
     */
    public function handleWebhook(array $payload): ?array
    {
        $data = $payload['data'] ?? [];

        if (! is_array($data) || ! isset($data['orderCode'])) {
            return null;
        }

        $booking = VehicleBooking::query()->where('payos_order_code', (int) $data['orderCode'])->first();
        if (! $booking) {
            return null;
        }

        $paid = (string) ($data['code'] ?? $payload['code'] ?? '') === '00';
        $amount = (int) ($data['amount'] ?? 0);

        if ($paid && $amount >= (int) $booking->total_amount) {
            $this->bookings->markPaid($booking, $amount);
        } elseif ($paid) {
            // Thiếu tiền: không xác nhận đơn, log để nhân viên đối soát/hoàn.
            Log::warning('Thuê xe: PayOS báo thiếu tiền', ['booking' => $booking->code, 'expected' => $booking->total_amount, 'got' => $amount]);
        }

        return ['error' => 0, 'message' => 'Vehicle rental webhook processed'];
    }

    private function newOrderCode(): int
    {
        do {
            $code = (int) (self::ORDER_CODE_PREFIX . substr((string) (int) (microtime(true) * 1000), -13));
        } while (VehicleBooking::query()->where('payos_order_code', $code)->exists());

        return $code;
    }
}

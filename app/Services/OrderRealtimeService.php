<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OrderRealtimeService
{
    private function wsUrl(): string
    {
        return rtrim(config('services.websocket.url', 'http://localhost:3001'), '/');
    }

    private function wsKey(): string
    {
        return config('services.websocket.internal_key', '');
    }

    /**
     * Broadcast cập nhật đơn hàng (guest_count, services) đến client đang xem đơn.
     *
     * @param  string     $orderCode
     * @param  array      $order     Payload gửi cho client (summary của thay đổi)
     * @param  int|null   $customerId  Nếu biết customer_id (logged-in), push thêm qua kênh cá nhân
     */
    public function broadcastOrderUpdate(string $orderCode, array $order, ?int $customerId = null): void
    {
        $url = $this->wsUrl();
        if (empty($url)) {
            return;
        }

        try {
            Http::withHeaders(['x-internal-key' => $this->wsKey()])
                ->timeout(2)
                ->post("{$url}/internal/order-update", [
                    'order_code'  => $orderCode,
                    'customer_id' => $customerId,
                    'order'       => $order,
                ]);
        } catch (\Throwable $e) {
            Log::warning('WS order-update push failed', [
                'order_code' => $orderCode,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Broadcast thông báo đơn hàng bị xóa (admin xóa) → app ẩn đơn ngay.
     */
    public function broadcastOrderDeleted(string $orderCode, ?int $customerId = null): void
    {
        $url = $this->wsUrl();
        if (empty($url)) {
            return;
        }

        try {
            Http::withHeaders(['x-internal-key' => $this->wsKey()])
                ->timeout(2)
                ->post("{$url}/internal/order-deleted", [
                    'order_code'  => $orderCode,
                    'customer_id' => $customerId,
                ]);
        } catch (\Throwable $e) {
            Log::warning('WS order-deleted push failed', [
                'order_code' => $orderCode,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Broadcast xác nhận mở cổng thành công (check-in hoặc check-out).
     *
     * @param  string       $type        'checkin' | 'checkout'
     * @param  string|null  $checkedInAt ISO timestamp của thời điểm check-in (chỉ dùng khi type=checkin)
     */
    public function broadcastCheckin(string $orderCode, string $type, ?string $checkedInAt = null, ?int $customerId = null): void
    {
        $url = $this->wsUrl();
        if (empty($url)) {
            return;
        }

        try {
            Http::withHeaders(['x-internal-key' => $this->wsKey()])
                ->timeout(2)
                ->post("{$url}/internal/order-checkin", [
                    'order_code'    => $orderCode,
                    'customer_id'   => $customerId,
                    'type'          => $type,
                    'checked_in_at' => $checkedInAt,
                ]);
        } catch (\Throwable $e) {
            Log::warning('WS order-checkin push failed', [
                'order_code' => $orderCode,
                'type'       => $type,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Broadcast thông báo mã cổng/phòng thay đổi.
     *
     * @param  string    $orderCode
     * @param  string    $newCode
     * @param  string    $type       'manual' | 'ttlock'
     * @param  int|null  $customerId
     */
    public function broadcastCodeChanged(string $orderCode, string $newCode, string $type = 'manual', ?int $customerId = null): void
    {
        $url = $this->wsUrl();
        if (empty($url)) {
            return;
        }

        try {
            Http::withHeaders(['x-internal-key' => $this->wsKey()])
                ->timeout(2)
                ->post("{$url}/internal/order-code-changed", [
                    'order_code'  => $orderCode,
                    'customer_id' => $customerId,
                    'new_code'    => $newCode,
                    'type'        => $type,
                ]);
        } catch (\Throwable $e) {
            Log::warning('WS order-code-changed push failed', [
                'order_code' => $orderCode,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}

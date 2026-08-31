<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\NotificationFcm;
use App\Models\NotificationFcmRecipient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Payment\Entities\Order;

/**
 * Gửi push notification cho customer VÀ lưu vào DB
 * để hiển thị trong GET /api/notifications.
 * Sau khi lưu DB, đẩy realtime qua Node WS server (PM2).
 */
class NotificationFcmService
{
    public function __construct(private readonly FcmService $fcm) {}

    /**
     * Gửi cho 1 customer cụ thể.
     */
    public function sendToCustomer(
        Customer $customer,
        string $title,
        string $body,
        string $type = 'manual',
        array $extra = [],
        ?string $url = null,
    ): void {
        $notification = NotificationFcm::create([
            'title'    => $title,
            'body'     => $body,
            'url'      => $url,
            'type'     => $type,
            'data'     => $extra ?: null,
            'sent_for' => 'users',
            'sent_at'  => now(),
        ]);

        $status      = 'sent';
        $unreadCount = $this->getUnreadCount($customer->id) + 1;

        // FCM push (chỉ gửi nếu có token)
        if ($customer->token_device) {
            try {
                $this->fcm->sendToCustomer($customer, $title, $body, array_merge($extra, $this->urlExtra($notification), [
                    'notification_id' => (string) $notification->id,
                    'type'            => $type,
                    'unread_count'    => (string) $unreadCount,
                ]));
            } catch (\Throwable $e) {
                $status = 'failed';
                Log::warning("NotificationFcmService: FCM failed [{$type}]", [
                    'customer_id' => $customer->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        NotificationFcmRecipient::create([
            'notification_fcm_id' => $notification->id,
            'customer_id'         => $customer->id,
            'fcm_token'           => $customer->token_device,
            'status'              => $status,
        ]);

        $notification->update([
            'sent_count' => $status === 'sent' ? 1 : 0,
            'fail_count' => $status === 'failed' ? 1 : 0,
        ]);

        // WebSocket realtime (app đang mở nhận ngay)
        $this->pushToWebSocket($customer->id, [
            'id'           => $notification->id,
            'title'        => $title,
            'body'         => $body,
            'url'          => $notification->url,
            'type'         => $type,
            'is_read'      => false,
            'read_at'      => null,
            'sent_at'      => now()->toIso8601String(),
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Data payload bổ sung 'url' cho FCM — chỉ thêm key khi có giá trị, tránh gửi chuỗi rỗng
     * xuống app (dễ bị hiểu nhầm là có link điều hướng).
     */
    private function urlExtra(NotificationFcm $notification): array
    {
        return $notification->url ? ['url' => $notification->url] : [];
    }

    /**
     * Gửi cho nhiều customer dựa trên NotificationFcm record đã tạo sẵn.
     * Dùng khi Filament tạo record trước rồi mới gửi.
     *
     * @param  Customer[]|\Illuminate\Support\Collection  $customers
     */
    public function sendToExisting(NotificationFcm $notification, iterable $customers): void
    {
        $sentCount = 0;
        $failCount = 0;

        foreach ($customers as $customer) {
            $status      = 'sent';
            $unreadCount = $this->getUnreadCount($customer->id) + 1;

            if ($customer->token_device) {
                try {
                    $this->fcm->sendToCustomer($customer, $notification->title, $notification->body, array_merge($this->urlExtra($notification), [
                        'notification_id' => (string) $notification->id,
                        'type'            => $notification->type,
                        'unread_count'    => (string) $unreadCount,
                    ]));
                } catch (\Throwable $e) {
                    $status = 'failed';
                    Log::warning("NotificationFcmService: FCM failed [{$notification->type}]", [
                        'customer_id' => $customer->id,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }

            NotificationFcmRecipient::create([
                'notification_fcm_id' => $notification->id,
                'customer_id'         => $customer->id,
                'fcm_token'           => $customer->token_device,
                'status'              => $status,
            ]);

            $this->pushToWebSocket($customer->id, [
                'id'           => $notification->id,
                'title'        => $notification->title,
                'body'         => $notification->body,
                'url'          => $notification->url,
                'type'         => $notification->type,
                'is_read'      => false,
                'read_at'      => null,
                'sent_at'      => now()->toIso8601String(),
                'unread_count' => $unreadCount,
            ]);

            $status === 'sent' ? $sentCount++ : $failCount++;
        }

        $notification->update([
            'sent_count' => $sentCount,
            'fail_count' => $failCount,
            'sent_at'    => now(),
        ]);
    }

    /**
     * Gửi cho nhiều customer cùng lúc (1 notification record, nhiều recipients).
     *
     * @param  Customer[]|\Illuminate\Support\Collection  $customers
     */
    public function sendToMany(
        iterable $customers,
        string $title,
        string $body,
        string $type = 'manual',
        string $sentFor = 'users',
        array $extra = [],
        ?string $url = null,
    ): NotificationFcm {
        $notification = NotificationFcm::create([
            'title'    => $title,
            'body'     => $body,
            'url'      => $url,
            'type'     => $type,
            'data'     => $extra ?: null,
            'sent_for' => $sentFor,
            'sent_at'  => now(),
        ]);

        $sentCount = 0;
        $failCount = 0;

        foreach ($customers as $customer) {
            $status      = 'sent';
            $unreadCount = $this->getUnreadCount($customer->id) + 1;

            if ($customer->token_device) {
                try {
                    $this->fcm->sendToCustomer($customer, $title, $body, array_merge($extra, $this->urlExtra($notification), [
                        'notification_id' => (string) $notification->id,
                        'type'            => $type,
                        'unread_count'    => (string) $unreadCount,
                    ]));
                } catch (\Throwable $e) {
                    $status = 'failed';
                    Log::warning("NotificationFcmService: FCM failed [{$type}]", [
                        'customer_id' => $customer->id,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }

            NotificationFcmRecipient::create([
                'notification_fcm_id' => $notification->id,
                'customer_id'         => $customer->id,
                'fcm_token'           => $customer->token_device,
                'status'              => $status,
            ]);

            // WebSocket realtime cho từng customer
            $this->pushToWebSocket($customer->id, [
                'id'           => $notification->id,
                'title'        => $title,
                'body'         => $body,
                'url'          => $url,
                'type'         => $type,
                'is_read'      => false,
                'read_at'      => null,
                'sent_at'      => now()->toIso8601String(),
                'unread_count' => $unreadCount,
            ]);

            $status === 'sent' ? $sentCount++ : $failCount++;
        }

        $notification->update([
            'sent_count' => $sentCount,
            'fail_count' => $failCount,
        ]);

        return $notification;
    }

    /**
     * Gửi cho guest token từ NotificationFcm record đã tạo sẵn (order mode).
     */
    public function sendGuestToExisting(NotificationFcm $notification, string $token): void
    {
        $status = 'sent';

        try {
            $this->fcm->sendToToken($token, $notification->title, $notification->body, array_merge($this->urlExtra($notification), [
                'notification_id' => (string) $notification->id,
                'type'            => $notification->type,
            ]));
        } catch (\Throwable $e) {
            $status = 'failed';
            Log::warning('NotificationFcmService: guest FCM failed [order]', [
                'token_prefix' => substr($token, 0, 20),
                'error'        => $e->getMessage(),
            ]);
        }

        NotificationFcmRecipient::create([
            'notification_fcm_id' => $notification->id,
            'customer_id'         => null,
            'fcm_token'           => $token,
            'status'              => $status,
        ]);

        $notification->update([
            'sent_count' => $status === 'sent' ? 1 : 0,
            'fail_count' => $status === 'failed' ? 1 : 0,
            'sent_at'    => now(),
        ]);
    }

    /**
     * Gửi push notification cho guest (không có customer_id) và lưu vào DB
     * để hiển thị trong GET /api/guest/notifications.
     * Xác định bằng fcm_token vì guest không có tài khoản.
     */
    public function sendToGuestToken(
        string $token,
        string $title,
        string $body,
        string $type = 'manual',
        array $extra = [],
        ?string $url = null,
    ): void {
        $notification = NotificationFcm::create([
            'title'    => $title,
            'body'     => $body,
            'url'      => $url,
            'type'     => $type,
            'data'     => $extra ?: null,
            'sent_for' => 'guests',
            'sent_at'  => now(),
        ]);

        $status = 'sent';

        try {
            $this->fcm->sendToToken($token, $title, $body, array_merge($extra, $this->urlExtra($notification), [
                'notification_id' => (string) $notification->id,
                'type'            => $type,
            ]));
        } catch (\Throwable $e) {
            $status = 'failed';
            Log::warning("NotificationFcmService: guest FCM failed [{$type}]", [
                'token_prefix' => substr($token, 0, 20),
                'error'        => $e->getMessage(),
            ]);
        }

        NotificationFcmRecipient::create([
            'notification_fcm_id' => $notification->id,
            'customer_id'         => null,
            'fcm_token'           => $token,
            'status'              => $status,
        ]);

        $notification->update([
            'sent_count' => $status === 'sent' ? 1 : 0,
            'fail_count' => $status === 'failed' ? 1 : 0,
        ]);
    }

    /**
     * Gửi lại 1 notification đã tạo cho đúng nhóm người nhận ban đầu (suy ra từ sent_for +
     * các recipient đã lưu, vì form tạo mới không lưu lại danh sách customer_id đã chọn).
     * Mỗi lần gọi tạo thêm bản ghi recipient mới (không xoá lịch sử lần gửi trước), rồi tính
     * lại sent_count/fail_count theo TỔNG toàn bộ recipient để số liệu hiển thị luôn khớp với
     * "Danh sách người nhận" dù đã gửi lại bao nhiêu lần.
     */
    public function resend(NotificationFcm $notification): void
    {
        if ($notification->sent_for === 'order') {
            $orderId = $notification->recipient_ids['order_id'] ?? null;
            $order   = $orderId ? Order::with('customer')->find($orderId) : null;

            if ($order?->customer_id && $order->customer?->token_device) {
                $this->sendToExisting($notification, collect([$order->customer]));
            } elseif ($order?->device_token) {
                $this->sendGuestToExisting($notification, $order->device_token);
            }
        } else {
            $customerIds = $notification->recipients()
                ->whereNotNull('customer_id')
                ->distinct()
                ->pluck('customer_id');

            $customers = Customer::whereIn('id', $customerIds)
                ->whereNotNull('token_device')
                ->where('status', Customer::STATUS_ACTIVE)
                ->get();

            if ($customers->isNotEmpty()) {
                $this->sendToExisting($notification, $customers);
            }
        }

        $notification->update([
            'sent_count' => $notification->recipients()->where('status', 'sent')->count(),
            'fail_count' => $notification->recipients()->where('status', 'failed')->count(),
        ]);
    }

    /**
     * Gọi Node WS server để đẩy realtime đến customer đang kết nối.
     * Fire-and-forget: lỗi chỉ log, không throw.
     */
    private function pushToWebSocket(string $customerId, array $notification): void
    {
        $url = rtrim(config('services.websocket.url', 'http://localhost:3001'), '/');
        $key = config('services.websocket.internal_key', '');

        if (empty($url)) {
            return;
        }

        try {
            Http::withHeaders(['x-internal-key' => $key])
                ->timeout(2)
                ->post("{$url}/internal/notify", [
                    'customer_id'  => $customerId,
                    'notification' => $notification,
                ]);
        } catch (\Throwable $e) {
            Log::warning('WS push failed', ['customer_id' => $customerId, 'error' => $e->getMessage()]);
        }
    }

    private function getUnreadCount(string $customerId): int
    {
        return NotificationFcmRecipient::where('customer_id', $customerId)
            ->where('status', 'sent')
            ->whereNull('read_at')
            ->count();
    }
}

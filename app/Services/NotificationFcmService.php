<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\NotificationFcm;
use App\Models\NotificationFcmRecipient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
    ): void {
        $notification = NotificationFcm::create([
            'title'    => $title,
            'body'     => $body,
            'type'     => $type,
            'sent_for' => 'users',
            'sent_at'  => now(),
        ]);

        $status      = 'sent';
        $unreadCount = $this->getUnreadCount($customer->id) + 1;

        // FCM push (chỉ gửi nếu có token)
        if ($customer->token_device) {
            try {
                $this->fcm->sendToCustomer($customer, $title, $body, array_merge($extra, [
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
            'type'         => $type,
            'is_read'      => false,
            'read_at'      => null,
            'sent_at'      => now()->toIso8601String(),
            'unread_count' => $unreadCount,
        ]);
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
                    $this->fcm->sendToCustomer($customer, $notification->title, $notification->body, [
                        'notification_id' => (string) $notification->id,
                        'type'            => $notification->type,
                        'unread_count'    => (string) $unreadCount,
                    ]);
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
    ): NotificationFcm {
        $notification = NotificationFcm::create([
            'title'    => $title,
            'body'     => $body,
            'type'     => $type,
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
                    $this->fcm->sendToCustomer($customer, $title, $body, array_merge($extra, [
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

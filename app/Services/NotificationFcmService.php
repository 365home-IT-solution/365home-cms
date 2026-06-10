<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\NotificationFcm;
use App\Models\NotificationFcmRecipient;
use Illuminate\Support\Facades\Log;

/**
 * Gửi push notification cho customer VÀ lưu vào DB
 * để hiển thị trong GET /api/notifications.
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
        if (! $customer->token_device) {
            return;
        }

        $notification = NotificationFcm::create([
            'title'     => $title,
            'body'      => $body,
            'type'      => $type,
            'sent_for'  => 'users',
            'sent_at'   => now(),
        ]);

        $status = 'sent';

        try {
            $this->fcm->sendToCustomer($customer, $title, $body, $extra);
        } catch (\Throwable $e) {
            $status = 'failed';
            Log::warning("NotificationFcmService: FCM failed [{$type}]", [
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
            ]);
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
            'title'     => $title,
            'body'      => $body,
            'type'      => $type,
            'sent_for'  => $sentFor,
            'sent_at'   => now(),
        ]);

        $sentCount = 0;
        $failCount = 0;

        foreach ($customers as $customer) {
            if (! $customer->token_device) {
                continue;
            }

            $status = 'sent';

            try {
                $this->fcm->sendToCustomer($customer, $title, $body, $extra);
            } catch (\Throwable $e) {
                $status = 'failed';
                Log::warning("NotificationFcmService: FCM failed [{$type}]", [
                    'customer_id' => $customer->id,
                    'error'       => $e->getMessage(),
                ]);
            }

            NotificationFcmRecipient::create([
                'notification_fcm_id' => $notification->id,
                'customer_id'         => $customer->id,
                'fcm_token'           => $customer->token_device,
                'status'              => $status,
            ]);

            $status === 'sent' ? $sentCount++ : $failCount++;
        }

        $notification->update([
            'sent_count' => $sentCount,
            'fail_count' => $failCount,
        ]);

        return $notification;
    }
}

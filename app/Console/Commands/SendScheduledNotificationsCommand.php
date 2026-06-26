<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\NotificationFcm;
use App\Models\NotificationFcmRecipient;
use App\Services\FcmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendScheduledNotificationsCommand extends Command
{
    protected $signature   = 'notifications:send-scheduled';
    protected $description = 'Gửi thông báo FCM đã lên lịch đến giờ gửi';

    public function handle(FcmService $fcmService): int
    {
        $pending = NotificationFcm::whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->whereNull('sent_at')
            ->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        $this->info("Tìm thấy {$pending->count()} thông báo cần gửi.");

        foreach ($pending as $notification) {
            $this->sendNotification($notification, $fcmService);
        }

        return 0;
    }

    private function sendNotification(NotificationFcm $notification, FcmService $fcmService): void
    {
        $customerIds = $notification->recipient_ids ?? [];

        if (empty($customerIds)) {
            $notification->update(['sent_at' => now()]);
            $this->warn("  ⚠ Thông báo #{$notification->id} không có người nhận, bỏ qua.");

            return;
        }

        $customers = Customer::whereIn('id', $customerIds)
            ->whereNotNull('token_device')
            ->where('status', Customer::STATUS_ACTIVE)
            ->get();

        $sentCount = 0;
        $failCount = 0;

        foreach ($customers as $customer) {
            $status = 'sent';

            try {
                $fcmService->sendToCustomer($customer, $notification->title, $notification->body);
            } catch (\Throwable $e) {
                $status = 'failed';
                Log::error('FCM scheduled send failed', [
                    'notification_id' => $notification->id,
                    'customer_id'     => $customer->id,
                    'error'           => $e->getMessage(),
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
            'sent_at'    => now(),
        ]);

        $this->line("  ✅ #{$notification->id} \"{$notification->title}\": {$sentCount} thành công, {$failCount} thất bại.");
    }
}

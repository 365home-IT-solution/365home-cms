<?php

declare(strict_types=1);

namespace App\Console\Commands\Minihouse;

use Illuminate\Console\Command;
use Modules\Minihouse\App\Models\PortalBroadcast;
use Modules\Minihouse\App\Models\PortalNotification;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Services\PortalNotificationService;

// Mirror App\Console\Commands\SendScheduledNotificationsCommand (Home) — gửi thông báo Portal đã
// lên lịch khi tới giờ. Đăng ký chạy mỗi phút trong app/Console/Kernel.php (cùng lịch với bản Home).
class SendScheduledPortalBroadcastsCommand extends Command
{
    protected $signature = 'minihouse:send-scheduled-broadcasts';
    protected $description = 'Gửi thông báo đẩy Portal MiniHouse đã lên lịch đến giờ gửi';

    public function handle(): int
    {
        $pending = PortalBroadcast::whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->whereNull('sent_at')
            ->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        $this->info("Tìm thấy {$pending->count()} thông báo cần gửi.");

        foreach ($pending as $broadcast) {
            $this->sendBroadcast($broadcast);
        }

        return 0;
    }

    private function sendBroadcast(PortalBroadcast $broadcast): void
    {
        $tenantIds = $broadcast->tenant_ids ?? [];

        if (empty($tenantIds)) {
            $broadcast->update(['sent_at' => now()]);
            $this->warn("  ⚠ Thông báo #{$broadcast->id} không có người nhận, bỏ qua.");

            return;
        }

        $tenants = Tenant::whereIn('id', $tenantIds)->get();

        $result = PortalNotificationService::notifyMany($tenants, PortalNotification::TYPE_MANUAL, $broadcast->title, $broadcast->body, $broadcast->link);

        $broadcast->update([
            'sent_at'         => now(),
            'recipient_count' => $result['sent'],
        ]);

        $this->line("  ✅ #{$broadcast->id} \"{$broadcast->title}\": {$result['sent']} thành công, {$result['failed']} thất bại.");
    }
}

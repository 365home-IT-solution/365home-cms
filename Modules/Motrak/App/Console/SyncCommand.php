<?php

namespace Modules\Motrak\App\Console;

use Illuminate\Console\Command;
use Modules\Motrak\App\Models\MotrakSyncLog;
use Modules\Motrak\App\Services\MotrakSettingsService;
use Modules\Motrak\App\Services\MotrakSyncService;

/**
 * Đồng bộ GPS Motrak. Scheduler chạy mỗi phút với --scheduled; lệnh tự bỏ qua (im lặng) khi chưa bật/chưa có Token hoặc chưa tới chu kỳ cấu hình
 * ở CMS. Chạy tay: php artisan motrak:sync (không áp dụng điều kiện bật/chu kỳ).
 */
class SyncCommand extends Command
{
    protected $signature = 'motrak:sync {--scheduled : Chạy theo lịch (tôn trọng bật/tắt và chu kỳ)}';

    protected $description = 'Đồng bộ vị trí phương tiện từ Motrak vào 365home';

    public function handle(MotrakSyncService $sync, MotrakSettingsService $settings): int
    {
        $scheduled = (bool) $this->option('scheduled');

        if ($scheduled) {
            if (! $settings->isEnabled() || $settings->tokenState() !== MotrakSettingsService::TOKEN_SAVED || ! $this->isDue($settings->intervalMinutes())) {
                return self::SUCCESS;
            }
        }

        $log = $sync->sync($scheduled ? MotrakSyncLog::TYPE_SCHEDULED : MotrakSyncLog::TYPE_MANUAL);

        $this->line(sprintf(
            '[%s] %s — xe: %d, lưu được: %d, lỗi: %d, điểm mới: %d%s',
            $log->sync_type, $log->status, $log->total_vehicles, $log->success_count, $log->failed_count, $log->new_locations,
            $log->error_message ? ' — ' . $log->error_message : '',
        ));

        return $log->status === MotrakSyncLog::STATUS_FAILED ? self::FAILURE : self::SUCCESS;
    }

    private function isDue(int $intervalMinutes): bool
    {
        $last = MotrakSyncLog::query()->whereIn('sync_type', [MotrakSyncLog::TYPE_MANUAL, MotrakSyncLog::TYPE_SCHEDULED])
            ->where('status', '!=', MotrakSyncLog::STATUS_SKIPPED)->latest('started_at')->first();

        // Dung sai 5 giây để lần chạy hằng phút của scheduler không bị lệch nhịp.
        return ! $last || $last->started_at->diffInSeconds(now()) >= $intervalMinutes * 60 - 5;
    }
}

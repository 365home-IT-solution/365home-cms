<?php

namespace Modules\Motrak\App\Console;

use Illuminate\Console\Command;
use Modules\Motrak\App\Models\MotrakSyncLog;
use Modules\Motrak\App\Models\MotrakVehicleLocation;

/** Xoá lịch sử vị trí và nhật ký đồng bộ quá hạn lưu trữ (config motrak.history). Vị trí MỚI NHẤT của từng xe nằm ở bảng xe nên không bị ảnh hưởng. */
class PruneCommand extends Command
{
    protected $signature = 'motrak:prune';

    protected $description = 'Xoá lịch sử vị trí GPS và nhật ký đồng bộ quá hạn lưu trữ';

    public function handle(): int
    {
        $locDays = (int) config('motrak.history.retention_days', 90);
        $logDays = (int) config('motrak.history.sync_logs_retention_days', 90);

        $locations = MotrakVehicleLocation::query()->where('gps_time', '<', now()->subDays($locDays))->delete();
        $logs = MotrakSyncLog::query()->where('started_at', '<', now()->subDays($logDays))->delete();

        $this->info("Đã xoá {$locations} điểm lịch sử (> {$locDays} ngày) và {$logs} dòng nhật ký (> {$logDays} ngày).");

        return self::SUCCESS;
    }
}

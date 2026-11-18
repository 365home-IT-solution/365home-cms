<?php

namespace Modules\VehicleRental\App\Console;

use Illuminate\Console\Command;
use Modules\VehicleRental\App\Services\VehicleGpsAlertService;

/**
 * Đánh giá cảnh báo GPS của xe thuê dựa trên dữ liệu ĐÃ LƯU (không gọi Motrak). Chạy độc lập với đồng bộ để vẫn phát hiện "mất tín hiệu GPS khi đang thuê"
 * ngay cả khi API Motrak không còn trả dữ liệu mới.
 */
class GpsCheckCommand extends Command
{
    protected $signature = 'vehicle-rental:gps-check';

    protected $description = 'Kiểm tra cảnh báo GPS của xe thuê (ra khỏi khu vực, vượt tốc độ, mất tín hiệu, xe chạy khi không có đơn)';

    public function handle(VehicleGpsAlertService $alerts): int
    {
        $n = $alerts->evaluateAll();
        $this->info("Cảnh báo GPS mới: {$n}.");

        return self::SUCCESS;
    }
}

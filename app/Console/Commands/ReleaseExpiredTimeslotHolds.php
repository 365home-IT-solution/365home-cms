<?php

namespace App\Console\Commands;

use App\Services\TimeslotHoldService;
use Illuminate\Console\Command;

// Dọn các "hold" khung giờ (xem TimeslotHoldService) đã hết hạn TTL mà không ai chủ động bỏ
// chọn/lưu đơn (vd admin đóng tab, mất mạng giữa chừng) — chạy định kỳ (Kernel::schedule())
// để đảm bảo phía khách hàng (cập nhật trực tiếp qua real-time, không tự làm mới) luôn nhận được
// đúng sự kiện "released", không bị kẹt mãi ở trạng thái "đang khóa".
class ReleaseExpiredTimeslotHolds extends Command
{
    protected $signature = 'timeslot-holds:release-expired';

    protected $description = 'Giải phóng + báo real-time các hold khung giờ đã hết hạn TTL';

    public function handle(TimeslotHoldService $service): int
    {
        $count = $service->releaseExpiredHolds();

        if ($count > 0) {
            $this->info("Đã giải phóng {$count} hold khung giờ hết hạn.");
        }

        return self::SUCCESS;
    }
}

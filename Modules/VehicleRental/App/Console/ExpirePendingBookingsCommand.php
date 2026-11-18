<?php

namespace Modules\VehicleRental\App\Console;

use Illuminate\Console\Command;
use Modules\VehicleRental\App\Services\VehicleBookingService;

class ExpirePendingBookingsCommand extends Command
{
    protected $signature = 'vehicle-rental:expire-pending';

    protected $description = 'Huỷ các đơn thuê xe chưa thanh toán đã hết hạn giữ chỗ';

    public function handle(VehicleBookingService $bookings): int
    {
        $count = $bookings->expirePending();
        $this->info("Đã huỷ {$count} đơn thuê xe hết hạn giữ chỗ.");

        return self::SUCCESS;
    }
}

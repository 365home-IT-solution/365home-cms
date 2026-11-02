<?php

namespace App\Console\Commands;

use App\Services\Payment\CashPaymentService;
use Illuminate\Console\Command;

// Đơn tiền mặt tại quầy chưa thu tiền: nhắc đối tác lúc tới giờ nhận phòng, tự huỷ khi khách không đến, tự xác nhận đã thu khi khách đã ở xong — xem CashPaymentService.
class SweepCashOrders extends Command
{
    protected $signature = 'orders:cash-sweep';

    protected $description = 'Nhắc / tự huỷ / tự xác nhận các đơn tiền mặt tại quầy chưa thu tiền';

    public function handle(CashPaymentService $cash): int
    {
        $done = $cash->sweep();

        $this->info("Nhắc {$done['reminded']} đơn · huỷ {$done['cancelled']} đơn khách không đến · tự xác nhận {$done['auto_confirmed']} đơn đã ở xong.");

        return self::SUCCESS;
    }
}

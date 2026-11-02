<?php

namespace App\Console\Commands;

use App\Services\CaroService;
use Illuminate\Console\Command;

/**
 * Safety-net cho caro: xử thua các trận hết giờ lượt mà không bên nào mở lại (vd cả 2 đã thoát app) và dọn hàng chờ đã rời.
 */
class SweepCaroCommand extends Command
{
    protected $signature   = 'caro:sweep';
    protected $description = 'Xử thua trận caro hết giờ lượt và dọn hàng chờ ghép trận';

    public function handle(CaroService $service): int
    {
        [$timedOut, $purged] = $service->sweep();

        $this->info("Đã xử {$timedOut} trận hết giờ, dọn {$purged} dòng hàng chờ.");

        return self::SUCCESS;
    }
}

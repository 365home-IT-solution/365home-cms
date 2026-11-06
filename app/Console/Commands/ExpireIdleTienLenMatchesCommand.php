<?php

namespace App\Console\Commands;

use App\Services\TienLenRoomService;
use App\Services\TienLenService;
use Illuminate\Console\Command;

// Tiến lên (chạy mỗi phút): xử lý lượt quá giờ mà không ai gọi API (người treo máy ⇒ bot đánh thay), xử thua ván với bot bỏ dở quá lâu,
// đóng phòng chờ không còn ai.
class ExpireIdleTienLenMatchesCommand extends Command
{
    protected $signature   = 'tien-len:expire-idle {--minutes=30 : Ván với bot không có thao tác quá N phút thì xử thua}';
    protected $description = 'Xử lý lượt quá giờ, đóng ván bỏ dở và phòng không còn ai';

    public function handle(TienLenService $service, TienLenRoomService $rooms): int
    {
        $ticked  = $service->tickOverdue();
        $expired = $service->expireIdle((int) $this->option('minutes'));
        $closed  = $rooms->closeIdle();

        $this->info("Xử lý {$ticked} lượt quá giờ, đóng {$expired} ván bỏ dở, đóng {$closed} phòng trống.");

        return self::SUCCESS;
    }
}

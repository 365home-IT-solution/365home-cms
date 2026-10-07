<?php

namespace App\Console\Commands;

use App\Services\EscrowPayosService;
use App\Services\EscrowService;
use Illuminate\Console\Command;

// Chạy định kỳ (Kernel::schedule) cho ký quỹ đối tác: đề xuất trừ hết hạn phản hồi → tự trừ; cập nhật trạng thái khi hạn nạp
// ban đầu/hạn nạp bù trôi qua (cảnh báo, tạm ngưng hoặc mở bán lại); đóng yêu cầu nạp quá hạn.
class ProcessEscrow extends Command
{
    protected $signature = 'escrow:process';

    protected $description = 'Tự trừ đề xuất hết hạn phản hồi và cập nhật trạng thái ký quỹ đối tác';

    public function handle(EscrowService $escrow, EscrowPayosService $payos): int
    {
        $applied = $escrow->applyExpiredDeductions();
        $refreshed = $escrow->refreshAll();
        $expired = $payos->expireStaleDeposits();

        $this->info("Đề xuất tự trừ: {$applied}, đối tác cập nhật trạng thái: {$refreshed}, yêu cầu nạp quá hạn đóng: {$expired}");

        return self::SUCCESS;
    }
}

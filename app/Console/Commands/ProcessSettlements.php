<?php

namespace App\Console\Commands;

use App\Services\SettlementService;
use Illuminate\Console\Command;

// Chạy hằng ngày (Kernel::schedule) cho đối soát hoa hồng đối tác Homestay: sinh bảng đối soát cho kỳ vừa đóng
// (theo payment_cycle), nhắc đối tác trước hạn nộp, tự trừ ký quỹ khi quá hạn mà chưa nộp.
class ProcessSettlements extends Command
{
    protected $signature = 'settlements:process';

    protected $description = 'Sinh bảng đối soát kỳ vừa đóng, nhắc hạn nộp hoa hồng và tự trừ ký quỹ khi quá hạn';

    public function handle(SettlementService $settlements): int
    {
        \Illuminate\Support\Facades\Cache::forever('finance:heartbeat:settlements:process', now());

        $finalized = app(\App\Services\OrderCommissionService::class)->finalizeEndedStays();
        $generated = $settlements->generateDue();
        $reminded = $settlements->remindDue();
        $deducted = $settlements->deductOverdue();

        $this->info("Đơn quá giờ trả phòng được chốt hoa hồng: {$finalized}, bảng đối soát mới: {$generated}, nhắc hạn: {$reminded}, tự trừ ký quỹ: {$deducted}");

        return self::SUCCESS;
    }
}

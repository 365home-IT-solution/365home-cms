<?php

namespace App\Console\Commands\Minihouse;

use Illuminate\Console\Command;
use Modules\Minihouse\App\Services\ResidenceDeclarationService;

// Khai báo lưu trú (KBTT) là nghĩa vụ pháp lý có hạn cụ thể (declarationDeadline()) — model đã có
// sẵn isOverdue()/needsDeclarationToday() nhưng trước đây KHÔNG có cron nào dùng tới, hoàn toàn phụ
// thuộc nhân viên tự nhớ vào xem bộ lọc trên trang. Lệnh này chạy hàng ngày, tự thông báo (chuông,
// xem ResidenceDeclarationService::notifyDue()) cho nhân viên phụ trách đúng toà nhà — gửi LẶP LẠI
// mỗi ngày cho tới khi được đánh dấu "Đã khai báo" (khác Reminder thường chỉ bắn 1 lần).
class NotifyResidenceDeclarationsCommand extends Command
{
    protected $signature = 'minihouse:notify-residence-declarations';

    protected $description = 'Nhắc nhân viên MiniHouse các Khai báo lưu trú đến hạn/quá hạn chưa nộp';

    public function handle(): int
    {
        app(ResidenceDeclarationService::class)->notifyDue();

        $this->info('Đã kiểm tra và gửi nhắc Khai báo lưu trú đến hạn/quá hạn.');

        return self::SUCCESS;
    }
}

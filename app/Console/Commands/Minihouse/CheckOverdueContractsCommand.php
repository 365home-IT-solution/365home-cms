<?php

namespace App\Console\Commands\Minihouse;

use Illuminate\Console\Command;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Reminder;

// Không có cơ chế nào tự chuyển Contract.status='active' sang 'expired' khi end_date đã qua — nếu
// nhân viên quên bấm "Thanh lý"/"Gia hạn", hợp đồng vẫn 'active' vô thời hạn VÀ vẫn tiếp tục được
// InvoiceGenerationService lập hoá đơn hàng tháng (chỉ lọc theo status, không so với end_date).
// Lệnh này KHÔNG tự đổi status (tự động chuyển có thể sai nếu 2 bên đang thương lượng gia hạn) —
// chỉ tự tạo 1 Reminder loại "Nhắc hết hạn hợp đồng" để nhân viên chủ động xử lý, tận dụng lại đúng
// kênh thông báo đã có (chuông + Zalo, xem ReminderNotificationService/MinihouseZaloService) qua
// cron minihouse:send-reminder-notifications chạy sau lệnh này.
class CheckOverdueContractsCommand extends Command
{
    protected $signature = 'minihouse:check-overdue-contracts';

    protected $description = 'Tạo nhắc việc cho các hợp đồng MiniHouse đã quá hạn (end_date đã qua) nhưng chưa được thanh lý/gia hạn';

    public function handle(): int
    {
        $overdueContracts = Contract::query()
            ->withoutGlobalScopes()
            ->where('status', Contract::STATUS_ACTIVE)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', now()->toDateString())
            ->get();

        $created = 0;

        foreach ($overdueContracts as $contract) {
            // Đã có 1 Reminder loại này CHƯA XONG cho đúng hợp đồng này thì không tạo thêm — tránh
            // spam nhắc việc trùng lặp mỗi ngày chạy lệnh trong lúc nhân viên chưa kịp xử lý xong.
            $alreadyReminded = Reminder::query()
                ->withoutGlobalScopes()
                ->where('contract_id', $contract->id)
                ->where('type', Reminder::TYPE_CONTRACT)
                ->where('is_done', false)
                ->exists();

            if ($alreadyReminded) {
                continue;
            }

            Reminder::create([
                'title'       => 'Hợp đồng #' . $contract->id . ' đã quá hạn, chưa thanh lý/gia hạn',
                'content'     => 'Hợp đồng kết thúc ngày ' . $contract->end_date->format('d/m/Y') . ' nhưng vẫn đang "Đang hiệu lực". Vào trang Hợp đồng để Gia hạn hoặc Thanh lý.',
                'type'        => Reminder::TYPE_CONTRACT,
                'contract_id' => $contract->id,
                'remind_date' => now(),
            ]);

            $created++;
        }

        $this->info("Đã kiểm tra {$overdueContracts->count()} hợp đồng quá hạn, tạo mới {$created} nhắc việc.");

        return self::SUCCESS;
    }
}

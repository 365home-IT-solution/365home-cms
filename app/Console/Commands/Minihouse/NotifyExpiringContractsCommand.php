<?php

namespace App\Console\Commands\Minihouse;

use Illuminate\Console\Command;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Reminder;
use Modules\Minihouse\App\Models\Room;

// Khác CheckOverdueContractsCommand (chỉ tạo nhắc việc NỘI BỘ SAU KHI hợp đồng đã quá hạn, cho nhân
// viên tự xử lý) — lệnh này tạo 1 Reminder (type=het_han_hop_dong) TRƯỚC ngày hết hạn đúng số ngày
// cấu hình ở Building.contract_expiry_reminder_days_before, và Reminder có contract_id nên
// MinihouseZaloService::resolveRecipient() tự gửi thẳng cho KHÁCH THUÊ (không phải nhân viên) qua
// cron minihouse:send-reminder-notifications chạy sau lệnh này — chủ nhà không cần tự nhớ theo dõi
// từng hợp đồng sắp hết hạn để chủ động mời khách gia hạn.
class NotifyExpiringContractsCommand extends Command
{
    protected $signature = 'minihouse:notify-expiring-contracts';

    protected $description = 'Tạo nhắc việc + gửi Zalo cho khách thuê có hợp đồng sắp hết hạn (theo Building.contract_expiry_reminder_days_before)';

    public function handle(): int
    {
        $buildingIds = Building::query()
            ->whereNotNull('contract_expiry_reminder_days_before')
            ->pluck('contract_expiry_reminder_days_before', 'id');

        if ($buildingIds->isEmpty()) {
            $this->info('Không có toà nhà nào bật nhắc gia hạn hợp đồng trước hạn.');

            return self::SUCCESS;
        }

        $created = 0;
        $checked = 0;

        foreach ($buildingIds as $buildingId => $daysBefore) {
            $roomIds = Room::withoutGlobalScopes()->where('building_id', $buildingId)->pluck('id');

            $expiringContracts = Contract::withoutGlobalScopes()
                ->where('status', Contract::STATUS_ACTIVE)
                ->whereIn('room_id', $roomIds)
                ->whereNotNull('end_date')
                ->whereDate('end_date', now()->addDays($daysBefore)->toDateString())
                ->get();

            $checked += $expiringContracts->count();

            foreach ($expiringContracts as $contract) {
                // Đã có 1 Reminder loại này CHƯA XONG cho đúng hợp đồng này thì không tạo thêm —
                // tránh gửi trùng nếu lệnh chạy lại nhiều lần trong lúc chưa xử lý xong (gia hạn/huỷ).
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
                    'title'       => 'Hợp đồng #' . $contract->id . ' sắp hết hạn, nhắc khách gia hạn',
                    'content'     => 'Hợp đồng thuê phòng của quý khách sẽ hết hạn vào ngày ' . $contract->end_date->format('d/m/Y') . '. Vui lòng liên hệ chủ nhà để gia hạn nếu có nhu cầu tiếp tục thuê.',
                    'type'        => Reminder::TYPE_CONTRACT,
                    'contract_id' => $contract->id,
                    'remind_date' => now(),
                ]);

                $created++;
            }
        }

        $this->info("Đã kiểm tra {$checked} hợp đồng sắp hết hạn, tạo mới {$created} nhắc việc.");

        return self::SUCCESS;
    }
}

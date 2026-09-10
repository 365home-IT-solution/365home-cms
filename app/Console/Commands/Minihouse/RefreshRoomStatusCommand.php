<?php

namespace App\Console\Commands\Minihouse;

use Illuminate\Console\Command;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Observers\ContractObserver;

// Tự chuyển phòng "Đã đặt cọc" (Room::STATUS_RESERVED — hợp đồng đang hiệu lực nhưng chưa tới ngày
// dọn vào) sang "Đã thuê" đúng ngày start_date tới, và ngược lại bắt các trường hợp lệch trạng thái
// khác nếu có — xem ContractObserver::syncRoom(). Chỉ dựa vào sự kiện sửa Hợp đồng (created/updated)
// là KHÔNG ĐỦ: 1 hợp đồng đặt cọc giữ chỗ trước rồi không ai đụng vào giữa chừng sẽ mãi kẹt ở "Đã
// đặt cọc" quá ngày dọn vào thật nếu thiếu cron này. Chạy hàng ngày (xem app/Console/Kernel.php).
class RefreshRoomStatusCommand extends Command
{
    protected $signature = 'minihouse:refresh-room-status';

    protected $description = 'Tự đồng bộ lại trạng thái phòng theo đúng ngày bắt đầu hợp đồng (Đã đặt cọc -> Đã thuê)';

    public function handle(): int
    {
        $roomIds = Contract::withoutGlobalScopes()
            ->where('status', Contract::STATUS_ACTIVE)
            ->distinct()
            ->pluck('room_id');

        $observer = app(ContractObserver::class);

        foreach ($roomIds as $roomId) {
            $observer->syncRoom($roomId);
        }

        $this->info("Đã kiểm tra lại trạng thái {$roomIds->count()} phòng đang có hợp đồng hiệu lực.");

        return self::SUCCESS;
    }
}

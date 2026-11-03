<?php

namespace App\Console\Commands;

use App\Services\BranchServiceSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\Product;

/**
 * Sửa dịch vụ đi kèm của phòng bị lệch so với chi nhánh (room_additional_service_assigns là bảng dẫn xuất của
 * additional_service_branches — xem BranchServiceSync). Chỉ đụng dịch vụ, không đồng bộ bảng giá như syncAll().
 * --dry-run: chạy trong transaction rồi rollback, chỉ báo phòng nào sẽ đổi.
 */
class SyncRoomServicesCommand extends Command
{
    protected $signature   = 'services:sync-rooms {--dry-run : Chỉ báo phòng lệch, không ghi}';
    protected $description = 'Đồng bộ lại dịch vụ đi kèm của mọi phòng theo chi nhánh của phòng';

    public function handle(BranchServiceSync $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows   = [];

        DB::beginTransaction();

        try {
            foreach (Product::withoutGlobalScopes()->orderBy('name')->get(['id', 'name']) as $room) {
                [$added, $removed] = $sync->syncRoomServices((string) $room->id);

                if ($added || $removed) {
                    $rows[] = [$room->name, $added, $removed];
                }
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if (! $rows) {
            $this->info('Dịch vụ của mọi phòng đã khớp chi nhánh.');

            return self::SUCCESS;
        }

        $this->table(['Phòng', 'Thêm', 'Bỏ'], $rows);
        $this->info(($dryRun ? '[dry-run] Sẽ sửa ' : 'Đã sửa ') . count($rows) . ' phòng.');

        return self::SUCCESS;
    }
}

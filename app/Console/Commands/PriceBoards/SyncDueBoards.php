<?php

namespace App\Console\Commands\PriceBoards;

use App\Services\PriceBoards\PriceBoardV2Service;
use App\Services\PriceBoardSyncService;
use Illuminate\Console\Command;
use Modules\Product\App\Models\Product;
use Throwable;

class SyncDueBoards extends Command
{
    protected $signature = 'price-boards:sync-due';

    protected $description = 'Áp/khôi phục giá phòng theo bảng giá đang hiệu lực hôm nay (chạy hàng ngày)';

    public function handle(PriceBoardSyncService $service, PriceBoardV2Service $boards): int
    {
        $count  = 0;
        $failed = 0;

        // Bảng phạm vi chi nhánh / toàn đối tác: bắt kịp phòng mới / phòng đổi chi nhánh trước khi áp giá.
        try {
            $this->info('Đã làm mới ' . $boards->rematerializeScoped() . ' bảng giá theo phạm vi.');
        } catch (Throwable $e) {
            $this->error('Lỗi làm mới bảng giá theo phạm vi: ' . $e->getMessage());
            report($e);
        }

        Product::query()->chunkById(100, function ($products) use ($service, &$count, &$failed) {
            foreach ($products as $product) {
                // Không để 1 phòng lỗi làm dừng cả job — các phòng còn lại (kể cả trong các chunk
                // sau) vẫn phải được đồng bộ đêm đó.
                try {
                    $service->applyForProduct($product);
                    $count++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("Lỗi đồng bộ bảng giá cho phòng #{$product->id}: {$e->getMessage()}");
                    report($e);
                }
            }
        });

        $this->info("Đã đồng bộ bảng giá cho {$count} phòng." . ($failed > 0 ? " Lỗi: {$failed} phòng." : ''));

        return self::SUCCESS;
    }
}

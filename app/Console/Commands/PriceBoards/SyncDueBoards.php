<?php

namespace App\Console\Commands\PriceBoards;

use App\Services\PriceBoardSyncService;
use Illuminate\Console\Command;
use Modules\Product\App\Models\Product;

class SyncDueBoards extends Command
{
    protected $signature = 'price-boards:sync-due';

    protected $description = 'Áp/khôi phục giá phòng theo bảng giá đang hiệu lực hôm nay (chạy hàng ngày)';

    public function handle(PriceBoardSyncService $service): int
    {
        $count = 0;

        Product::query()->chunkById(100, function ($products) use ($service, &$count) {
            foreach ($products as $product) {
                $service->applyForProduct($product);
                $count++;
            }
        });

        $this->info("Đã đồng bộ bảng giá cho {$count} phòng.");

        return self::SUCCESS;
    }
}

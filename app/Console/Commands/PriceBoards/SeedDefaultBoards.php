<?php

namespace App\Console\Commands\PriceBoards;

use App\Services\PriceBoardSyncService;
use Illuminate\Console\Command;
use Modules\Product\App\Models\Product;

/**
 * Chạy 1 LẦN sau khi deploy migration bảng giá — tạo "Bảng giá mặc định" từ dữ liệu products/
 * room_time_slots hiện có của từng phòng, để trang "Hệ thống giá" và luồng đặt phòng không bị gián
 * đoạn (xem plan "Chuyển module Hệ thống giá thành module Bảng giá").
 */
class SeedDefaultBoards extends Command
{
    protected $signature = 'price-boards:seed-defaults';

    protected $description = 'Khởi tạo bảng giá mặc định cho toàn bộ phòng từ dữ liệu giá hiện có (chạy 1 lần)';

    public function handle(PriceBoardSyncService $service): int
    {
        $count = 0;

        Product::query()->chunkById(100, function ($products) use ($service, &$count) {
            foreach ($products as $product) {
                $service->seedDefaultBoard($product);
                $count++;
            }
        });

        $this->info("Đã tạo bảng giá mặc định cho {$count} phòng.");

        return self::SUCCESS;
    }
}

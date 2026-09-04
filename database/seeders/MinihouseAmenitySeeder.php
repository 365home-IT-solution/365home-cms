<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Minihouse\App\Models\Amenity;

// Tiện ích mẫu ban đầu cho panel MiniHouse — CRUD được ở "Tiện ích", đây chỉ là gợi ý khởi tạo cho
// đỡ trống trang. An toàn chạy nhiều lần (firstOrCreate). Gọi tay trên môi trường đã có dữ liệu:
// php artisan db:seed --class=MinihouseAmenitySeeder
class MinihouseAmenitySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Điều hoà', 'Nóng lạnh', 'Wifi', 'Tủ lạnh', 'Máy giặt', 'Ban công', 'Thang máy', 'Gác xép'] as $name) {
            Amenity::firstOrCreate(['name' => $name]);
        }

        $this->command?->info('MinihouseAmenitySeeder: đã tạo tiện ích mẫu.');
    }
}

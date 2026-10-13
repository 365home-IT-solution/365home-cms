<?php

namespace Database\Seeders;

use App\Models\MiniGame;
use Illuminate\Database\Seeder;

// Tạo sẵn game "Thẻ bài" (chưa có lá nào). Lá bài và ảnh thêm ở Admin > Quản lý API > Mini game. Chạy lại nhiều lần không tạo trùng.
class MiniGameSeeder extends Seeder
{
    public function run(): void
    {
        MiniGame::firstOrCreate(
            ['code' => 'the-bai'],
            [
                'name'        => 'Thẻ bài',
                'description' => 'Trộn bài rồi chọn một lá bất kỳ để khám phá thử thách dành cho hai bạn.',
                'is_active'   => true,
                'sort_order'  => 1,
            ],
        );
    }
}

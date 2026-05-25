<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Product\App\Models\Product;

class RoomAdditionalServiceSeeder extends Seeder
{
    // Gán tất cả dịch vụ bổ sung vào 8 phòng hiện có.
    // Chạy idempotent: insertOrIgnore bỏ qua nếu cặp đã tồn tại.
    public function run(): void
    {
        $roomNames = ['PIXEL', 'NEON', 'CRIMSON', 'PINKY', 'VELVET', 'GALLERY', 'LUMEN', 'MIDNIGHT'];

        $roomIds = Product::query()->whereIn('name', $roomNames)->pluck('id');
        $serviceIds = AdditionService::pluck('id');

        if ($roomIds->isEmpty() || $serviceIds->isEmpty()) {
            $this->command->warn('RoomAdditionalServiceSeeder: không tìm thấy phòng hoặc dịch vụ, bỏ qua.');
            return;
        }

        $rows = [];

        foreach ($roomIds as $roomId) {
            foreach ($serviceIds as $serviceId) {
                $rows[] = [
                    'room_id'               => $roomId,
                    'additional_service_id' => $serviceId,
                ];
            }
        }

        DB::table('room_additional_service_assigns')->insertOrIgnore($rows);

        $this->command->info("RoomAdditionalServiceSeeder: đã gán {$serviceIds->count()} dịch vụ × {$roomIds->count()} phòng.");
    }
}

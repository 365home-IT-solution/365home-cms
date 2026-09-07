<?php

namespace Modules\Minihouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Room;

// Dữ liệu mẫu để test bộ lọc/quản lý theo Toà nhà — 3 toà nhà, mỗi toà 2 tầng x 4 phòng (đủ để
// RoomOccupancyMapWidget nhóm theo tầng trên Dashboard). Seeder ĐỘC LẬP, chạy tay khi cần (không gọi
// trong DatabaseSeeder): php artisan db:seed --class="Modules\Minihouse\Database\Seeders\BuildingRoomSeeder".
// An toàn chạy lại nhiều lần — firstOrCreate theo tên toà/mã phòng, không tạo trùng, không ghi đè
// field đã sửa tay (chỉ tự điền 'floor' cho phòng cũ trước khi có cột này, nếu đang trống).
class BuildingRoomSeeder extends Seeder
{
    public const ROOMS_PER_FLOOR = 4;
    public const FLOORS          = 2;

    public function run(): void
    {
        $buildings = [
            ['name' => 'Toà nhà A - Quận 1', 'code_prefix' => 'A', 'address' => '12 Nguyễn Huệ, Phường Bến Nghé', 'price' => 3200000, 'electric' => 3800, 'water' => 20000],
            ['name' => 'Toà nhà B - Quận 3', 'code_prefix' => 'B', 'address' => '45 Võ Văn Tần, Phường 6', 'price' => 2800000, 'electric' => 3500, 'water' => 18000],
            ['name' => 'Toà nhà C - Bình Thạnh', 'code_prefix' => 'C', 'address' => '78 Điện Biên Phủ, Phường 15', 'price' => 2500000, 'electric' => 3500, 'water' => 18000],
        ];

        foreach ($buildings as $data) {
            $building = Building::firstOrCreate(
                ['name' => $data['name']],
                [
                    'address'             => $data['address'],
                    'electric_unit_price' => $data['electric'],
                    'water_unit_price'    => $data['water'],
                ]
            );

            $totalRooms = self::FLOORS * self::ROOMS_PER_FLOOR;

            for ($i = 1; $i <= $totalRooms; $i++) {
                $code  = sprintf('%s-%02d', $data['code_prefix'], $i);
                $floor = (int) ceil($i / self::ROOMS_PER_FLOOR);

                // Vị trí trên sơ đồ (2 hàng x 2 cột / tầng) — minh hoạ dãy phòng 2 bên hành lang,
                // xem RoomOccupancyMapWidget. Chỉ là dữ liệu MẪU, quản lý thật tự sửa lại đúng mặt
                // bằng ở trang Sửa phòng.
                $indexOnFloor = (($i - 1) % self::ROOMS_PER_FLOOR) + 1;
                $row = (int) ceil($indexOnFloor / 2);
                $col = (($indexOnFloor - 1) % 2) + 1;

                $room = Room::firstOrCreate(
                    ['building_id' => $building->id, 'code' => $code],
                    [
                        'floor'        => $floor,
                        'position_row' => $row,
                        'position_col' => $col,
                        'area'         => 20 + ($i * 2),
                        'price'        => $data['price'] + (($i - 1) * 100000),
                        'status'       => Room::STATUS_EMPTY,
                    ]
                );

                if (is_null($room->floor)) {
                    $room->update(['floor' => $floor]);
                }

                if (is_null($room->position_row) && is_null($room->position_col)) {
                    $room->update(['position_row' => $row, 'position_col' => $col]);
                }
            }
        }

        $this->command?->info('BuildingRoomSeeder: đã tạo ' . count($buildings) . ' toà nhà, mỗi toà ' . (self::FLOORS * self::ROOMS_PER_FLOOR) . ' phòng (' . self::FLOORS . ' tầng).');
    }
}

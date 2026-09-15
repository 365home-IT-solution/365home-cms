<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Audit phát hiện: RoomForm (Filament) chặn 2 phòng CÙNG toà nhà + CÙNG tầng trùng hàng/cột
// (uniquePositionRule — sơ đồ phòng trên Dashboard chỉ vẽ được 1 phòng/ô) nhưng API tạo/sửa phòng
// KHÔNG có chặn tương tự — 2 cửa vào cùng 1 dữ liệu cho ra 2 kết quả khác nhau. Test này khoá lại
// guard vừa thêm ở RoomController::store()/update().
class RoomPositionUniqueGuardTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cannot_create_room_at_duplicate_position_via_api(): void
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        Room::create([
            'building_id' => $building->id, 'code' => 'R1-' . uniqid(), 'price' => 1000000,
            'status' => Room::STATUS_EMPTY, 'floor' => 2, 'position_row' => 3, 'position_col' => 4,
        ]);

        $admin = User::role('super_admin')->first();
        $token = $admin->createToken('t')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/admin/minihouse/rooms', [
                'building_id' => $building->id, 'code' => 'R2-' . uniqid(), 'price' => 1000000,
                'floor' => 2, 'position_row' => 3, 'position_col' => 4,
            ]);

        $response->assertStatus(422);
    }

    public function test_can_create_room_at_different_position_via_api(): void
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        Room::create([
            'building_id' => $building->id, 'code' => 'R1-' . uniqid(), 'price' => 1000000,
            'status' => Room::STATUS_EMPTY, 'floor' => 2, 'position_row' => 3, 'position_col' => 4,
        ]);

        $admin = User::role('super_admin')->first();
        $token = $admin->createToken('t')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/admin/minihouse/rooms', [
                'building_id' => $building->id, 'code' => 'R2-' . uniqid(), 'price' => 1000000,
                'floor' => 2, 'position_row' => 3, 'position_col' => 5,
            ]);

        $response->assertStatus(201);
    }
}

<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Audit phát hiện: Room.status có thể bị sửa tay tự do (VD đổi sang "Trống") trong khi phòng vẫn có
// Hợp đồng "Đang hiệu lực" — bộ chọn phòng khi tạo hợp đồng mới lọc theo status=trong nên có thể gán
// nhầm phòng đang có khách cho khách khác. Test này khoá lại chặn ở cả API lẫn Room::hasActiveContract().
class RoomStatusGuardTest extends TestCase
{
    use DatabaseTransactions;

    private function makeRoomWithActiveContract(): Room
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-' . uniqid(), 'price' => 3000000, 'status' => Room::STATUS_RENTED]);
        $tenant = Tenant::create(['fullname' => 'T', 'phone' => '09' . random_int(10000000, 99999999)]);
        Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => now()->subMonth(), 'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);

        return $room;
    }

    public function test_room_model_reports_has_active_contract_correctly(): void
    {
        $room = $this->makeRoomWithActiveContract();
        $this->assertTrue($room->hasActiveContract());

        $emptyRoom = Room::create(['building_id' => $room->building_id, 'code' => 'R2-' . uniqid(), 'price' => 1000000, 'status' => Room::STATUS_EMPTY]);
        $this->assertFalse($emptyRoom->hasActiveContract());
    }

    public function test_api_blocks_manually_setting_status_to_empty_while_contract_active(): void
    {
        $room = $this->makeRoomWithActiveContract();
        $admin = User::role('super_admin')->first();
        $token = $admin->createToken('t')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->putJson("/api/admin/minihouse/rooms/{$room->id}", ['status' => Room::STATUS_EMPTY]);

        $response->assertStatus(422);
        $this->assertSame(Room::STATUS_RENTED, $room->fresh()->status);
    }

    public function test_api_still_allows_setting_status_to_rented_while_contract_active(): void
    {
        $room = $this->makeRoomWithActiveContract();
        $admin = User::role('super_admin')->first();
        $token = $admin->createToken('t')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->putJson("/api/admin/minihouse/rooms/{$room->id}", ['status' => Room::STATUS_RENTED, 'note' => 'x']);

        $response->assertOk();
    }
}

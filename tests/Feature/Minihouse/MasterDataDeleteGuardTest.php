<?php

namespace Tests\Feature\Minihouse;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Exceptions\CannotDeleteReferencedRecordException;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Audit phát hiện: Zone/Building/Room dùng SoftDeletes nên xoá chỉ set deleted_at, KHÔNG kích hoạt
// cascade FK thật ở CSDL — xoá 1 cha khi con vẫn tham chiếu tới sẽ để con trỏ về 1 bản ghi "biến
// mất" (quan hệ trả về NULL do SoftDeletingScope). Test này khoá lại 3 guard mới thêm ở
// Zone/Building/Room::booted().
class MasterDataDeleteGuardTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cannot_delete_zone_with_buildings(): void
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);

        $this->expectException(CannotDeleteReferencedRecordException::class);

        try {
            $zone->delete();
        } finally {
            $this->assertNotSoftDeleted($zone);
        }
    }

    public function test_cannot_delete_building_with_rooms(): void
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        Room::create(['building_id' => $building->id, 'code' => 'R-' . uniqid(), 'price' => 1000000, 'status' => Room::STATUS_EMPTY]);

        $this->expectException(CannotDeleteReferencedRecordException::class);

        try {
            $building->delete();
        } finally {
            $this->assertNotSoftDeleted($building);
        }
    }

    public function test_cannot_delete_room_with_any_contract_even_expired(): void
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-' . uniqid(), 'price' => 1000000, 'status' => Room::STATUS_EMPTY]);
        $tenant = Tenant::create(['fullname' => 'T', 'phone' => '09' . random_int(10000000, 99999999)]);
        Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => now()->subYear(), 'end_date' => now()->subMonth(), 'monthly_price' => 1000000, 'deposit_amount' => 1000000,
            'status' => Contract::STATUS_EXPIRED,
        ]);

        $this->expectException(CannotDeleteReferencedRecordException::class);

        try {
            $room->delete();
        } finally {
            $this->assertNotSoftDeleted($room);
        }
    }

    public function test_empty_zone_building_room_can_still_be_deleted_normally(): void
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-' . uniqid(), 'price' => 1000000, 'status' => Room::STATUS_EMPTY]);

        $room->delete();
        $this->assertSoftDeleted($room);

        $building->delete();
        $this->assertSoftDeleted($building);

        $zone->delete();
        $this->assertSoftDeleted($zone);
    }
}

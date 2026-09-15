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

// Bổ sung chức năng "Khôi phục bản ghi đã xoá" (TrashedFilter/RestoreAction/ForceDeleteAction) cho
// 9 bảng dùng SoftDeletes (Zone/Building/Room/Contract/Invoice/Tenant/Surcharge/Transaction/
// Reminder). Test này khoá lại đúng phần rủi ro thật của tính năng: restore() hoạt động đúng, và
// forceDelete() vẫn bị chặn bởi các guard tham chiếu đã có (Zone/Building/Room::booted()) — KHÔNG
// test phần click UI/Livewire (Filament tự đảm bảo phần đó, xem RestoreAction/ForceDeleteAction).
class TrashRestoreGuardTest extends TestCase
{
    use DatabaseTransactions;

    public function test_restoring_a_soft_deleted_zone_makes_it_visible_again(): void
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $zone->delete();
        $this->assertSoftDeleted($zone);

        $zone->restore();

        $this->assertDatabaseHas('minihouse_zones', ['id' => $zone->id, 'deleted_at' => null]);
        $this->assertNotNull(Zone::find($zone->id));
    }

    public function test_force_deleting_a_zone_still_referenced_by_a_building_is_blocked(): void
    {
        // Soft-delete TRƯỚC khi có Toà nhà nào tham chiếu (soft-delete 1 zone rỗng luôn được phép) —
        // rồi mới gắn 1 Toà nhà vào zone ĐÃ TRASH đó (mô phỏng dữ liệu cũ/import thẳng, không qua form
        // Filament) để có đúng tình huống cần test: zone đã trash NHƯNG vẫn còn con tham chiếu.
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $zone->delete();
        Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);

        $this->expectException(CannotDeleteReferencedRecordException::class);

        try {
            $zone->forceDelete();
        } finally {
            $this->assertSoftDeleted($zone);
        }
    }

    public function test_force_deleting_an_empty_trashed_zone_succeeds(): void
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $zone->delete();

        $zone->forceDelete();

        $this->assertDatabaseMissing('minihouse_zones', ['id' => $zone->id]);
    }

    public function test_force_deleting_a_room_still_referenced_by_a_contract_is_blocked(): void
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-' . uniqid(), 'price' => 1000000, 'status' => Room::STATUS_EMPTY]);
        // Soft-delete phòng khi CHƯA có hợp đồng nào (được phép) — rồi mới gắn hợp đồng vào phòng
        // ĐÃ TRASH (mô phỏng dữ liệu cũ/import) để test đúng tình huống: phòng đã trash nhưng vẫn còn
        // hợp đồng tham chiếu.
        $room->delete();
        $tenant = Tenant::create(['fullname' => 'T', 'phone' => '09' . random_int(10000000, 99999999)]);
        Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => now()->subYear(), 'end_date' => now()->subMonth(), 'monthly_price' => 1000000, 'deposit_amount' => 1000000,
            'status' => Contract::STATUS_EXPIRED,
        ]);

        $this->expectException(CannotDeleteReferencedRecordException::class);

        try {
            $room->forceDelete();
        } finally {
            $this->assertSoftDeleted($room);
        }
    }

    public function test_authorizes_by_permission_trait_grants_restore_and_force_delete_to_super_admin(): void
    {
        $admin = \App\Models\User::role('super_admin')->first();
        $this->assertNotNull($admin);
        $this->actingAs($admin);

        $resource = \Modules\Minihouse\App\Filament\Resources\ZoneResource::class;

        $this->assertTrue($resource::canRestoreAny());
        $this->assertTrue($resource::canForceDeleteAny());
        $this->assertTrue($resource::canDeleteAny());
    }
}

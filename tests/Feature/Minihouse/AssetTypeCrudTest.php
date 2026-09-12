<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\AssetType;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\RoomAsset;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Module "Loại tài sản" (AssetTypeResource) — thêm 2026-09-11 theo yêu cầu người dùng ("nếu nhiều
// phòng thì nhập như vậy rất lâu, tôi muốn ở phòng chỉ có thể chọn thôi"), trước đó CHƯA có test tự
// động nào. Test này khoá lại: (1) tên loại tài sản phải duy nhất — cùng ràng buộc unique() ở DB, (2)
// RoomAsset.name vẫn là cột string bình thường, chọn từ danh mục AssetType chỉ đổi TRẢI NGHIỆM NHẬP
// LIỆU (Select thay TextInput), không đổi cấu trúc dữ liệu đã lưu — 1 RoomAsset không bị buộc phải
// khớp CHÍNH XÁC 1 AssetType nào (cho phép dữ liệu cũ nhập tay tự do trước khi có danh mục này vẫn
// hoạt động bình thường).
class AssetTypeCrudTest extends TestCase
{
    use DatabaseTransactions;

    public function test_asset_type_name_must_be_unique(): void
    {
        $name = 'May lanh ' . uniqid();
        AssetType::create(['name' => $name]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        AssetType::create(['name' => $name]);
    }

    public function test_admin_can_create_and_edit_asset_type_via_panel(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);

        $response = $this->actingAs($admin)->get('/minihouse-admin/asset-types/create');
        $response->assertOk();

        $assetType = AssetType::create(['name' => 'Tu lanh ' . uniqid()]);

        $response = $this->actingAs($admin)->get("/minihouse-admin/asset-types/{$assetType->id}/edit");
        $response->assertOk();
        $response->assertSee('Tu lanh');
    }

    public function test_room_asset_name_does_not_require_matching_an_asset_type_catalog_entry(): void
    {
        // Dữ liệu CŨ nhập tay tự do trước khi có danh mục AssetType — vẫn phải hoạt động bình thường
        // (RoomAsset.name là cột string thường, không có FK ràng buộc tới minihouse_asset_types).
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-' . uniqid(), 'price' => 1000000, 'status' => Room::STATUS_EMPTY]);

        $asset = RoomAsset::create(['room_id' => $room->id, 'name' => 'Ten tuy y khong co trong danh muc', 'condition' => RoomAsset::CONDITION_GOOD]);

        $this->assertDatabaseHas('minihouse_room_assets', ['id' => $asset->id, 'name' => 'Ten tuy y khong co trong danh muc']);
        $this->assertSame(0, AssetType::where('name', 'Ten tuy y khong co trong danh muc')->count());
    }
}

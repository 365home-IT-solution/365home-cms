<?php

namespace Tests\Feature\Minihouse;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Trang công khai giới thiệu phòng MiniHouse trên giao diện Home (không cần đăng nhập) — xem
// App\Http\Controllers\Minihouse\Public\StorefrontController. Chỉ hiện phòng đang "Trống"
// (Room::scopeAvailable()) của toà đang bật, KHÔNG dùng chung route/luồng đặt phòng ngắn hạn của
// Home (BladeThemeV1Controller) nên các test này không cần lo ảnh hưởng dữ liệu Homestay.
class PublicStorefrontTest extends TestCase
{
    use DatabaseTransactions;

    private Building $building;

    protected function setUp(): void
    {
        parent::setUp();

        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $this->building = Building::create([
            'zone_id' => $zone->id, 'name' => 'Storefront Test Building', 'address' => '123 Test St',
            'electric_unit_price' => 3500, 'water_unit_price' => 15000, 'status' => true,
        ]);
    }

    public function test_index_lists_only_available_rooms(): void
    {
        $available = Room::create(['building_id' => $this->building->id, 'code' => 'PUB-01', 'price' => 3000000, 'status' => Room::STATUS_EMPTY]);
        Room::create(['building_id' => $this->building->id, 'code' => 'PUB-02', 'price' => 3500000, 'status' => Room::STATUS_RENTED]);

        $response = $this->get('/minihouse');

        $response->assertOk();
        $response->assertSee('PUB-01');
        $response->assertDontSee('PUB-02');
        // Nhóm theo địa chỉ toà nhà (không phải tên) — xem index.blade.php.
        $response->assertSee($available->building->address);
    }

    public function test_index_hides_rooms_from_inactive_building(): void
    {
        $inactiveZone = Zone::create(['name' => 'ZI' . uniqid()]);
        $inactiveBuilding = Building::create([
            'zone_id' => $inactiveZone->id, 'name' => 'Inactive Building', 'address' => 'x',
            'electric_unit_price' => 3500, 'water_unit_price' => 15000,
        ]);
        // 'status' KHÔNG nằm trong $fillable của Building (categories.status) — phải gán tay sau
        // khi tạo, không truyền được qua mảng create().
        $inactiveBuilding->status = false;
        $inactiveBuilding->save();
        Room::create(['building_id' => $inactiveBuilding->id, 'code' => 'PUB-INACTIVE', 'price' => 3000000, 'status' => Room::STATUS_EMPTY]);

        $response = $this->get('/minihouse');

        $response->assertOk();
        $response->assertDontSee('PUB-INACTIVE');
    }

    // Không còn form lọc thủ công (đã bỏ theo yêu cầu, cho giống hệt trang danh sách Homestay —
    // trang đó không có bộ lọc giá/tên riêng) — chỉ còn building_id qua query string, dùng bởi
    // breadcrumb ở trang chi tiết ("Tên toà nhà" trỏ về đây kèm building_id để quay lại đúng
    // danh sách của toà đó).
    public function test_index_filters_by_building_id_from_breadcrumb_link(): void
    {
        $otherZone = Zone::create(['name' => 'ZB' . uniqid()]);
        $otherBuilding = Building::create(['zone_id' => $otherZone->id, 'name' => 'Other', 'address' => 'b', 'electric_unit_price' => 3500, 'water_unit_price' => 15000]);

        Room::create(['building_id' => $this->building->id, 'code' => 'PUB-MINE', 'price' => 3000000, 'status' => Room::STATUS_EMPTY]);
        Room::create(['building_id' => $otherBuilding->id, 'code' => 'PUB-OTHER-BUILDING', 'price' => 3000000, 'status' => Room::STATUS_EMPTY]);

        $response = $this->get('/minihouse?building_id=' . $this->building->id);

        $response->assertOk();
        $response->assertSee('PUB-MINE');
        $response->assertDontSee('PUB-OTHER-BUILDING');
    }

    public function test_show_renders_available_room_detail(): void
    {
        $room = Room::create(['building_id' => $this->building->id, 'code' => 'PUB-DETAIL', 'price' => 3200000, 'status' => Room::STATUS_EMPTY, 'area' => 20]);

        $response = $this->get('/minihouse/' . $room->slug);

        $response->assertOk();
        $response->assertSee('PUB-DETAIL');
        $response->assertSee('3.200.000');
        $response->assertSee('Liên hệ tư vấn');
    }

    public function test_show_returns_404_for_occupied_room(): void
    {
        $room = Room::create(['building_id' => $this->building->id, 'code' => 'PUB-OCCUPIED', 'price' => 3000000, 'status' => Room::STATUS_RENTED]);

        $this->get('/minihouse/' . $room->slug)->assertNotFound();
    }

    public function test_show_returns_404_for_room_in_inactive_building(): void
    {
        $inactiveZone = Zone::create(['name' => 'ZI2' . uniqid()]);
        $inactiveBuilding = Building::create([
            'zone_id' => $inactiveZone->id, 'name' => 'Inactive Building 2', 'address' => 'x',
            'electric_unit_price' => 3500, 'water_unit_price' => 15000,
        ]);
        $inactiveBuilding->status = false;
        $inactiveBuilding->save();
        $room = Room::create(['building_id' => $inactiveBuilding->id, 'code' => 'PUB-HIDDEN', 'price' => 3000000, 'status' => Room::STATUS_EMPTY]);

        $this->get('/minihouse/' . $room->slug)->assertNotFound();
    }

    // Trang chủ Home vẫn phải load bình thường và KHÔNG lộ dữ liệu MiniHouse qua luồng tìm phòng
    // ngắn hạn chung (route product.search) — xác nhận việc thêm trang MiniHouse không đụng gì tới
    // route/luồng có sẵn của Home.
    public function test_homestay_search_route_still_works_and_stays_isolated(): void
    {
        Room::create(['building_id' => $this->building->id, 'code' => 'PUB-ISOLATION', 'price' => 3000000, 'status' => Room::STATUS_EMPTY]);

        $response = $this->get(route('product.search'));

        $response->assertOk();
        $response->assertDontSee('PUB-ISOLATION');
    }
}

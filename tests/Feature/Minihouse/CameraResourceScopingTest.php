<?php

namespace Tests\Feature\Minihouse;

use App\Models\Camera;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Minihouse\App\Support\HomestayBridge;
use Tests\TestCase;

// Camera của Home dùng App\Models\Concerns\BelongsToBranch/BelongsToPartner — 2 trait CHỈ áp global
// scope khi App\Support\AdminPanelContext::isActive() (middleware riêng panel "admin", KHÔNG chạy ở
// panel "minihouse-admin"). Test này khoá lại đúng rủi ro rò rỉ dữ liệu 2 chiều đã phát hiện khi mang
// tính năng Camera sang MiniHouse: Modules\Minihouse\App\Filament\Resources\CameraResource PHẢI tự
// lọc lại bằng getEloquentQuery() — camera của Home không bao giờ được lộ sang panel MiniHouse.
class CameraResourceScopingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_minihouse_camera_resource_only_shows_minihouse_cameras_not_homes(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);

        // Camera thật của Home — đối tác/chi nhánh Home bình thường, KHÔNG liên quan MiniHouse.
        $homePartner = Partner::create(['name' => 'Doi tac Home ' . uniqid(), 'status' => true]);
        $homeBranch = Category::create([
            'name'         => $homeName = 'Chi nhanh Home ' . uniqid(),
            'slug'         => Str::slug($homeName),
            'category_type' => 'product',
            'parent_id'    => null,
            'partner_id'   => $homePartner->id,
            'status'       => true,
        ]);
        $homeCamera = Camera::create([
            'partner_id'  => $homePartner->id,
            'branch_id'   => $homeBranch->id,
            'name'        => 'Camera Home ' . uniqid(),
            'stream_key'  => 'home-' . uniqid(),
            'status'      => true,
        ]);

        // Camera thật của MiniHouse — Toà nhà gốc, partner_id cố định của MiniHouse.
        $mhBuilding = Category::create([
            'name'         => $mhName = 'Toa nha MiniHouse ' . uniqid(),
            'slug'         => Str::slug($mhName),
            'category_type' => 'product',
            'parent_id'    => null,
            'partner_id'   => HomestayBridge::PARTNER_ID,
            'status'       => true,
        ]);
        $mhCamera = Camera::create([
            'partner_id'  => HomestayBridge::PARTNER_ID,
            'branch_id'   => $mhBuilding->id,
            'name'        => 'Camera MiniHouse ' . uniqid(),
            'stream_key'  => 'mh-' . uniqid(),
            'status'      => true,
        ]);

        $response = $this->actingAs($admin)->get('/minihouse/admin/cameras');
        $response->assertOk();
        $response->assertSee($mhCamera->name);
        $response->assertDontSee($homeCamera->name);
    }

    public function test_minihouse_camera_monitor_page_only_lists_minihouse_cameras(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);

        $homePartner = Partner::create(['name' => 'Doi tac Home ' . uniqid(), 'status' => true]);
        $homeBranch = Category::create([
            'name'         => $homeName = 'Chi nhanh Home ' . uniqid(),
            'slug'         => Str::slug($homeName),
            'category_type' => 'product',
            'parent_id'    => null,
            'partner_id'   => $homePartner->id,
            'status'       => true,
        ]);
        $homeCamera = Camera::create([
            'partner_id'  => $homePartner->id,
            'branch_id'   => $homeBranch->id,
            'name'        => 'Camera Home ' . uniqid(),
            'stream_key'  => 'home-' . uniqid(),
            'status'      => true,
        ]);

        $mhBuilding = Category::create([
            'name'         => $mhName = 'Toa nha MiniHouse ' . uniqid(),
            'slug'         => Str::slug($mhName),
            'category_type' => 'product',
            'parent_id'    => null,
            'partner_id'   => HomestayBridge::PARTNER_ID,
            'status'       => true,
        ]);
        $mhCamera = Camera::create([
            'partner_id'  => HomestayBridge::PARTNER_ID,
            'branch_id'   => $mhBuilding->id,
            'name'        => 'Camera MiniHouse ' . uniqid(),
            'stream_key'  => 'mh-' . uniqid(),
            'status'      => true,
        ]);

        $page = new \Modules\Minihouse\App\Filament\Pages\CameraMonitor();
        $this->actingAs($admin);

        $cameras = $page->getCameras();

        // KHÔNG assert tổng số lượng tuyệt đối — CSDL dev có thể có sẵn camera MiniHouse khác từ
        // trước, không liên quan phép thử này. Chỉ cần khẳng định đúng camera MiniHouse vừa tạo XUẤT
        // HIỆN và camera Home vừa tạo KHÔNG hề lẫn vào.
        $this->assertTrue($cameras->contains('id', $mhCamera->id));
        $this->assertFalse($cameras->contains('id', $homeCamera->id));
    }
}

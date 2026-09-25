<?php

namespace Tests\Feature\Minihouse;

use App\Models\Partner;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Category\Entities\Category;
use Modules\Minihouse\App\Filament\Resources\CameraResource\Pages\CreateCamera;
use Modules\Minihouse\App\Models\Camera;
use Modules\Minihouse\App\Models\CameraSetting;
use Modules\Minihouse\App\Support\HomestayBridge;
use Tests\TestCase;

// Yêu cầu 2026-09-25: đổi trang "Cấu hình Camera" của MiniHouse từ 1 cấu hình DUY NHẤT dùng chung
// (giống Home cũ, lọc theo đối tác) sang lọc THEO TỪNG TOÀ NHÀ — vì MiniHouse chỉ có 1 đối tác nội
// bộ cố định nên lọc theo đối tác vô nghĩa, mỗi toà nhà là 1 địa điểm vật lý có thể có server Frigate
// riêng. Khoá lại: (1) 2 toà nhà khác nhau có thể có base_url khác nhau, (2)
// Modules\Minihouse\App\Models\Camera::wsProxyUrl() (kế thừa App\Models\Camera) đọc ĐÚNG cấu hình
// của toà nhà chứa camera đó, không lẫn giữa 2 toà nhà, (3) trang cấu hình chỉ liệt kê Toà nhà (không
// còn Select đối tác).
class CameraBuildingSettingsTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    private function makeBuilding(): Category
    {
        $name = 'Toa nha ' . uniqid();

        return Category::create([
            'name'          => $name,
            'slug'          => Str::slug($name) . '-' . uniqid(),
            'category_type' => 'product',
            'parent_id'     => null,
            'partner_id'    => HomestayBridge::PARTNER_ID,
            'status'        => true,
        ]);
    }

    public function test_two_buildings_can_have_different_camera_server_configs(): void
    {
        $buildingA = $this->makeBuilding();
        $buildingB = $this->makeBuilding();

        CameraSetting::forBuilding($buildingA->id)->fill(['base_url' => 'http://server-a:1984'])->save();
        CameraSetting::forBuilding($buildingB->id)->fill(['base_url' => 'http://server-b:1984'])->save();

        $this->assertSame('http://server-a:1984', CameraSetting::forBuilding($buildingA->id)->base_url);
        $this->assertSame('http://server-b:1984', CameraSetting::forBuilding($buildingB->id)->base_url);
    }

    public function test_camera_wsproxyurl_resolves_settings_for_its_own_building(): void
    {
        config(['services.websocket.public_url' => 'http://proxy.test']);

        $buildingA = $this->makeBuilding();
        $buildingB = $this->makeBuilding();

        CameraSetting::forBuilding($buildingA->id)->fill(['base_url' => 'http://server-a:1984'])->save();
        // buildingB CHƯA cấu hình gì — camera của nó phải trả về null, KHÔNG lẫn sang server của A.

        $cameraA = Camera::create([
            'partner_id' => HomestayBridge::PARTNER_ID,
            'branch_id'  => $buildingA->id,
            'name'       => 'Cam A',
            'stream_key' => 'cam-a-' . uniqid(),
            'status'     => true,
        ]);
        $cameraB = Camera::create([
            'partner_id' => HomestayBridge::PARTNER_ID,
            'branch_id'  => $buildingB->id,
            'name'       => 'Cam B',
            'stream_key' => 'cam-b-' . uniqid(),
            'status'     => true,
        ]);

        $this->assertNotNull($cameraA->wsProxyUrl());
        $this->assertNull($cameraB->wsProxyUrl());
    }

    public function test_settings_page_lists_buildings_not_partners(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);

        $building = $this->makeBuilding();

        $response = $this->actingAs($admin)->get('/minihouse/admin/manage-camera-settings');
        $response->assertOk();
        $response->assertSee($building->name);
        $response->assertDontSee('Chọn đối tác');
    }

    // Bug thật đã gặp (2026-09-25): tạo camera MiniHouse trùng "tên nguồn" (stream_key) với 1 camera
    // Home ĐÃ CÓ SẴN bị chặn "trùng", dù 2 bên chạy 2 server go2rtc hoàn toàn khác nhau (Home lọc
    // theo đối tác, MiniHouse lọc theo Toà nhà — không cùng 1 server nên tên trùng không xung đột
    // thật sự). Khoá lại: cùng stream_key với Home hoặc Toà nhà KHÁC vẫn tạo được, nhưng trùng trong
    // CÙNG 1 Toà nhà (cùng 1 server thật) vẫn phải bị chặn như cũ.
    public function test_stream_key_only_needs_to_be_unique_within_the_same_building(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('minihouse-admin'));
        $admin = User::role('super_admin')->first();
        $this->actingAs($admin);

        $sharedKey = 'shared-key-' . uniqid();

        $homePartner = Partner::create(['name' => 'Doi tac Home ' . uniqid(), 'status' => true]);
        $homeBranch = Category::create([
            'name'          => $homeName = 'Chi nhanh Home ' . uniqid(),
            'slug'          => Str::slug($homeName) . '-' . uniqid(),
            'category_type' => 'product',
            'parent_id'     => null,
            'partner_id'    => $homePartner->id,
            'status'        => true,
        ]);
        \App\Models\Camera::create([
            'partner_id' => $homePartner->id,
            'branch_id'  => $homeBranch->id,
            'name'       => 'Camera Home',
            'stream_key' => $sharedKey,
            'status'     => true,
        ]);

        $buildingA = $this->makeBuilding();
        $buildingB = $this->makeBuilding();

        Camera::create([
            'partner_id' => HomestayBridge::PARTNER_ID,
            'branch_id'  => $buildingA->id,
            'name'       => 'Camera A1',
            'stream_key' => $sharedKey,
            'status'     => true,
        ]);

        // Trùng tên với Home VÀ với Toà nhà A, nhưng tạo ở Toà nhà B (server khác) — PHẢI cho qua.
        Livewire::test(CreateCamera::class)
            ->fillForm([
                'name'       => 'Camera B1',
                'stream_key' => $sharedKey,
                'branch_id'  => $buildingB->id,
                'partner_id' => HomestayBridge::PARTNER_ID,
                'status'     => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // Trùng tên NGAY TRONG CÙNG Toà nhà A (cùng 1 server thật) — PHẢI bị chặn.
        Livewire::test(CreateCamera::class)
            ->fillForm([
                'name'       => 'Camera A2',
                'stream_key' => $sharedKey,
                'branch_id'  => $buildingA->id,
                'partner_id' => HomestayBridge::PARTNER_ID,
                'status'     => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['stream_key']);
    }
}

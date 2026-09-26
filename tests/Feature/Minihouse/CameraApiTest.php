<?php

namespace Tests\Feature\Minihouse;

use App\Models\Camera;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Minihouse\App\Models\CameraSetting;
use Modules\Minihouse\App\Support\HomestayBridge;
use Tests\TestCase;

// API riêng cho camera MiniHouse — mirror ĐÚNG bộ /api/admin/cameras* của Home (xem
// App\Http\Controllers\Api\Admin\Minihouse\CameraController/CameraRecordingController/
// CameraSettingsController) nhưng khoá theo building_id (Toà nhà, qua ScopesToMinihouseBuilding)
// thay vì partner_id/effectiveBranchIds() của Home.
class CameraApiTest extends TestCase
{
    use DatabaseTransactions;

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

    private function token(): string
    {
        return User::role('super_admin')->first()->createToken('t')->plainTextToken;
    }

    public function test_full_crud_via_minihouse_camera_api(): void
    {
        $building = $this->makeBuilding();
        $token    = $this->token();

        $create = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/admin/minihouse/cameras', [
                'name'        => 'Cam cong chinh',
                'stream_key'  => 'mh-api-' . uniqid(),
                'building_id' => $building->id,
                'status'      => true,
            ]);

        $create->assertStatus(201)
            ->assertJsonPath('data.name', 'Cam cong chinh')
            ->assertJsonPath('data.branch.id', $building->id);

        $id = $create->json('data.id');

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson("/api/admin/minihouse/cameras/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/admin/minihouse/cameras')
            ->assertOk()
            ->assertJsonFragment(['id' => $id]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->putJson("/api/admin/minihouse/cameras/{$id}", ['name' => 'Cam da doi ten'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Cam da doi ten');

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->deleteJson("/api/admin/minihouse/cameras/{$id}")
            ->assertOk();

        $this->assertDatabaseMissing('cameras', ['id' => $id]);
    }

    public function test_cannot_create_or_view_camera_outside_permitted_building_when_not_super_admin(): void
    {
        $allowedBuilding    = $this->makeBuilding();
        $notAllowedBuilding = $this->makeBuilding();

        $manager = User::role('super_admin')->first()->replicate();
        $manager->email = 'mh-manager-' . uniqid() . '@test.local';
        $manager->password = bcrypt('secret');
        $manager->save();
        $manager->syncRoles([]);
        $manager->givePermissionTo(['view_any_cameras', 'create_cameras']);
        $manager->minihouseBuildings()->attach($allowedBuilding->id);

        $token = $manager->createToken('t')->plainTextToken;

        // Toà được phép: tạo được bình thường.
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/admin/minihouse/cameras', [
                'name'        => 'Cam OK',
                'stream_key'  => 'mh-ok-' . uniqid(),
                'building_id' => $allowedBuilding->id,
            ])
            ->assertStatus(201);

        // Toà KHÔNG được phép: bị chặn 403, không tạo được.
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/admin/minihouse/cameras', [
                'name'        => 'Cam khong duoc phep',
                'stream_key'  => 'mh-blocked-' . uniqid(),
                'building_id' => $notAllowedBuilding->id,
            ])
            ->assertStatus(403);

        // Camera của Toà không được phép quản lý — không thấy trong danh sách/chi tiết.
        $foreignCamera = Camera::create([
            'partner_id' => HomestayBridge::PARTNER_ID,
            'branch_id'  => $notAllowedBuilding->id,
            'name'       => 'Cam nguoi khac',
            'stream_key' => 'mh-foreign-' . uniqid(),
            'status'     => true,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson("/api/admin/minihouse/cameras/{$foreignCamera->id}")
            ->assertStatus(404);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/admin/minihouse/cameras')
            ->assertOk()
            ->assertJsonMissing(['id' => $foreignCamera->id]);
    }

    public function test_stream_key_unique_per_building_not_across_whole_table(): void
    {
        $buildingA = $this->makeBuilding();
        $buildingB = $this->makeBuilding();
        $sharedKey = 'mh-shared-' . uniqid();
        $token     = $this->token();

        Camera::create([
            'partner_id' => HomestayBridge::PARTNER_ID,
            'branch_id'  => $buildingA->id,
            'name'       => 'Cam A',
            'stream_key' => $sharedKey,
            'status'     => true,
        ]);

        // Trùng tên nhưng Toà nhà KHÁC — được phép.
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/admin/minihouse/cameras', [
                'name'        => 'Cam B',
                'stream_key'  => $sharedKey,
                'building_id' => $buildingB->id,
            ])
            ->assertStatus(201);

        // Trùng tên NGAY TRONG CÙNG Toà nhà A — bị chặn.
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/admin/minihouse/cameras', [
                'name'        => 'Cam A2',
                'stream_key'  => $sharedKey,
                'building_id' => $buildingA->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['stream_key']);
    }

    public function test_camera_settings_api_reads_and_writes_per_building(): void
    {
        $building = $this->makeBuilding();
        $token    = $this->token();

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/admin/minihouse/camera-settings/buildings')
            ->assertOk()
            ->assertJsonFragment(['id' => $building->id]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/admin/minihouse/camera-settings?building_id=' . $building->id)
            ->assertOk()
            ->assertJsonPath('data.is_configured', false);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->putJson('/api/admin/minihouse/camera-settings', [
                'building_id' => $building->id,
                'base_url'    => 'http://frigate.example.test:5000',
                'username'    => 'admin',
                'password'    => 'secret',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_configured', true)
            ->assertJsonPath('data.has_password', true);

        $this->assertSame('http://frigate.example.test:5000', CameraSetting::forBuilding($building->id)->base_url);

        // API KHÔNG BAO GIỜ trả mật khẩu thật.
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/admin/minihouse/camera-settings?building_id=' . $building->id);
        $response->assertJsonMissingPath('data.password');
    }

    public function test_recording_endpoints_return_graceful_error_when_building_not_configured(): void
    {
        $building = $this->makeBuilding();
        $token    = $this->token();

        $camera = Camera::create([
            'partner_id' => HomestayBridge::PARTNER_ID,
            'branch_id'  => $building->id,
            'name'       => 'Cam chua cau hinh',
            'stream_key' => 'mh-noconf-' . uniqid(),
            'status'     => true,
        ]);

        // Chưa cấu hình Frigate cho Toà nhà này — phải trả lỗi 502 rõ ràng, KHÔNG crash/500.
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson("/api/admin/minihouse/cameras/{$camera->id}/recordings/summary")
            ->assertStatus(502);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/admin/minihouse/cameras/{$camera->id}/recording/start", [])
            ->assertStatus(502);
    }
}

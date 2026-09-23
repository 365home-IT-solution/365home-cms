<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Minihouse\App\Models\Tenant;
use Tests\TestCase;

// PHP KHÔNG tự parse multipart/form-data (ảnh CCCD) gửi qua PUT/PATCH (giới hạn của PHP, không
// phải Laravel) — Postman/nhiều client chỉ đính kèm file được qua POST, nên thêm route
// POST /tenants/{id} trỏ vào ĐÚNG TenantController::update(). Khoá lại: (1) POST kèm file ảnh thật
// lưu được và trả về đường dẫn, (2) PATCH/PUT vẫn nhận string path như cũ (không phá luồng Filament
// đang gửi path sau khi tự upload async), (3) ảnh quá khổ/không phải ảnh bị từ chối đúng lúc gửi
// file thật (không áp rule 'image' nhầm lên trường hợp gửi string).
class TenantIdCardUploadTest extends TestCase
{
    use DatabaseTransactions;

    private function adminHeaders(): array
    {
        $admin = User::role('super_admin')->first();

        return ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken];
    }

    public function test_post_update_with_real_image_file_stores_it_and_returns_path(): void
    {
        Storage::fake('public');

        $tenant = Tenant::create(['fullname' => 'Khách D', 'phone' => '09' . random_int(10000000, 99999999)]);
        $file = UploadedFile::fake()->image('cccd-truoc.jpg', 800, 500);

        $response = $this->withHeaders($this->adminHeaders())
            ->post("/api/admin/minihouse/tenants/{$tenant->id}", [
                'id_card_front' => $file,
            ]);

        $response->assertOk();
        $path = $response->json('data.id_card_front');
        $this->assertNotEmpty($path);
        $this->assertStringStartsWith('minihouse/tenants/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_post_create_with_real_image_file_works(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('cccd-sau.jpg', 800, 500);

        $response = $this->withHeaders($this->adminHeaders())
            ->post('/api/admin/minihouse/tenants', [
                'fullname'     => 'Khách E',
                'id_card_back' => $file,
            ]);

        $response->assertCreated();
        $path = $response->json('data.id_card_back');
        $this->assertNotEmpty($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_json_update_with_string_path_still_works_unchanged(): void
    {
        $tenant = Tenant::create(['fullname' => 'Khách F', 'phone' => '09' . random_int(10000000, 99999999)]);

        $response = $this->withHeaders($this->adminHeaders())
            ->patchJson("/api/admin/minihouse/tenants/{$tenant->id}", [
                'id_card_front' => 'minihouse/tenants/da-upload-san.jpg',
            ]);

        $response->assertOk();
        $this->assertSame('minihouse/tenants/da-upload-san.jpg', $response->json('data.id_card_front'));
    }

    public function test_oversized_or_non_image_file_is_rejected(): void
    {
        Storage::fake('public');

        $tenant = Tenant::create(['fullname' => 'Khách G', 'phone' => '09' . random_int(10000000, 99999999)]);
        $badFile = UploadedFile::fake()->create('cccd.pdf', 100, 'application/pdf');

        $this->withHeaders($this->adminHeaders())
            ->post("/api/admin/minihouse/tenants/{$tenant->id}", ['id_card_front' => $badFile])
            ->assertStatus(422);
    }
}

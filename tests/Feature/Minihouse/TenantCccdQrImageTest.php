<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Payment\App\Services\CccdScannerService;
use Tests\TestCase;

// Khách thuê MiniHouse: lưu ảnh CCCD mặt có mã QR (id_card_qr_image, song song với 2 mặt) qua API
// quản trị, và API quét độc lập cho chính khách thuê (portal). Tuổi KHÔNG chặn ở MiniHouse.
class TenantCccdQrImageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('cccd-scan:ip:127.0.0.1');
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');
        Http::fake();
    }

    private function mockQrScan(?array $data): void
    {
        $this->mock(CccdScannerService::class, function ($mock) use ($data) {
            $mock->shouldReceive('scanQrImage')->andReturn($data);
            $mock->shouldReceive('scanImage')->andReturn(null);
        });
    }

    private function person(array $override = []): array
    {
        return array_merge([
            'cccd'        => '087204016918',
            'old_id'      => '',
            'full_name'   => 'Nguyễn Văn A',
            'dob'         => '12/05/2004',
            'gender'      => 'Nam',
            'address'     => 'Đồng Tháp',
            'issued_date' => '01/01/2022',
            'source'      => 'qr',
        ], $override);
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->image('cccd.jpg', 1200, 800);
    }

    private function adminHeaders(): array
    {
        $admin = User::role('super_admin')->first();

        return ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function tenantHeaders(Tenant $tenant): array
    {
        return ['Authorization' => 'Bearer ' . $tenant->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    // ── Lưu ảnh QR qua API quản trị ──────────────────────────────────────────

    public function test_admin_creates_tenant_with_qr_image_and_blank_fields_are_filled_from_qr(): void
    {
        $this->mockQrScan($this->person());

        $response = $this->withHeaders($this->adminHeaders())->post('/api/admin/minihouse/tenants', [
            'fullname'         => 'Nguyễn Văn A',
            'id_card_qr_image' => $this->image(),
        ])->assertCreated()
            ->assertJsonPath('data.id_card_number', '087204016918')
            ->assertJsonPath('data.date_of_birth', '2004-05-12')
            ->assertJsonPath('data.id_card_front', null);

        $path = $response->json('data.id_card_qr_image');
        $this->assertNotNull($path);
        $this->assertTrue(Storage::disk('public')->exists($path));
    }

    public function test_admin_can_add_qr_image_to_existing_tenant_without_touching_filled_fields(): void
    {
        $this->mockQrScan($this->person());
        $tenant = Tenant::create(['fullname' => 'Tên Nhập Tay', 'permanent_address' => 'Địa chỉ nhập tay']);

        $this->withHeaders($this->adminHeaders())->post("/api/admin/minihouse/tenants/{$tenant->id}", [
            'id_card_qr_image' => $this->image(),
        ])->assertOk()
            ->assertJsonPath('data.fullname', 'Tên Nhập Tay')
            ->assertJsonPath('data.permanent_address', 'Địa chỉ nhập tay')
            ->assertJsonPath('data.id_card_number', '087204016918');

        $this->assertNotNull($tenant->refresh()->id_card_qr_image);
    }

    public function test_admin_tenant_without_qr_image_behaves_as_before(): void
    {
        $this->withHeaders($this->adminHeaders())->post('/api/admin/minihouse/tenants', ['fullname' => 'Không Ảnh'])
            ->assertCreated()
            ->assertJsonPath('data.id_card_qr_image', null)
            ->assertJsonPath('data.id_card_number', null);
    }

    public function test_admin_tenant_is_saved_even_when_qr_is_unreadable(): void
    {
        $this->mockQrScan(null);

        $this->withHeaders($this->adminHeaders())->post('/api/admin/minihouse/tenants', [
            'fullname'         => 'Ảnh Mờ',
            'id_card_qr_image' => $this->image(),
        ])->assertCreated()
            ->assertJsonPath('data.id_card_number', null);
    }

    // ── API quét của quản trị: tuổi chỉ để tham khảo ─────────────────────────

    public function test_admin_scan_reports_age_against_14_without_blocking(): void
    {
        $this->mockQrScan($this->person(['cccd' => '087215016918', 'dob' => '12/05/2015']));

        $this->withHeaders($this->adminHeaders())->post('/api/admin/minihouse/cccd/scan-qr', ['cccd_qr_image' => $this->image()])
            ->assertOk()
            ->assertJson(['scanned' => true, 'warnings' => [], 'min_age' => 14, 'under_age' => true]);
    }

    // ── API quét cho khách thuê (portal) ─────────────────────────────────────

    public function test_portal_scan_requires_tenant_token(): void
    {
        $this->postJson('/api/minihouse/portal/cccd/scan-qr')->assertStatus(401);

        // Token quản trị không dùng được cho API của khách thuê.
        $this->withHeaders($this->adminHeaders())->postJson('/api/minihouse/portal/cccd/scan-qr')
            ->assertStatus(403);
    }

    public function test_portal_scan_returns_data_and_does_not_change_the_profile(): void
    {
        $this->mockQrScan($this->person());
        $tenant = Tenant::create(['fullname' => 'Khách Thuê', 'phone' => '0911' . random_int(100000, 999999), 'id_card_number' => '087204016918']);

        $this->withHeaders($this->tenantHeaders($tenant))->post('/api/minihouse/portal/cccd/scan-qr', ['cccd_qr_image' => $this->image()])
            ->assertOk()
            ->assertJson([
                'scanned'         => true,
                'data'            => ['cccd' => '087204016918'],
                'tenant_fields'   => ['fullname' => 'Nguyễn Văn A', 'date_of_birth' => '2004-05-12'],
                'matches_profile' => true,
                'min_age'         => 14,
                'under_age'       => false,
            ]);

        $tenant->refresh();
        $this->assertSame('Khách Thuê', $tenant->fullname);
        $this->assertNull($tenant->id_card_qr_image);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_portal_scan_does_not_block_a_15_year_old(): void
    {
        $this->mockQrScan($this->person(['cccd' => '087211016918', 'dob' => '12/05/2011']));
        $tenant = Tenant::create(['fullname' => 'Khách Nhỏ Tuổi', 'phone' => '0911' . random_int(100000, 999999)]);

        $this->withHeaders($this->tenantHeaders($tenant))->post('/api/minihouse/portal/cccd/scan-qr', ['cccd_qr_image' => $this->image()])
            ->assertOk()
            ->assertJson(['age' => 15, 'under_age' => false, 'matches_profile' => null]);
    }

    public function test_portal_scan_rejects_unreadable_qr(): void
    {
        $this->mockQrScan(null);
        $tenant = Tenant::create(['fullname' => 'Khách Thuê', 'phone' => '0911' . random_int(100000, 999999)]);

        $this->withHeaders($this->tenantHeaders($tenant))->post('/api/minihouse/portal/cccd/scan-qr', ['cccd_qr_image' => $this->image()])
            ->assertStatus(422)
            ->assertJson(['code' => 'cccd_qr_unreadable', 'field' => 'cccd_qr_image']);
    }
}

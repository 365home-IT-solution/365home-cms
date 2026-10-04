<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Modules\Payment\App\Services\CccdScannerService;
use Tests\TestCase;

// Bản quản trị của endpoint quét 1 ảnh CCCD mặt có mã QR: POST /api/admin/cccd/scan-qr (homestay)
// và POST /api/admin/minihouse/cccd/scan-qr (minihouse) — không lưu ảnh, không đụng luồng 2 mặt.
class CccdScanQrAdminTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');
    }

    private function adminHeaders(): array
    {
        $admin = User::role('super_admin')->first();

        return ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function mockScan(?array $data): void
    {
        $this->mock(CccdScannerService::class, function ($mock) use ($data) {
            $mock->shouldReceive('scanQrImage')->andReturn($data);
        });
    }

    private function qrData(array $override = []): array
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

    public function test_both_endpoints_require_admin_token(): void
    {
        $this->postJson('/api/admin/cccd/scan-qr')->assertStatus(401);
        $this->postJson('/api/admin/minihouse/cccd/scan-qr')->assertStatus(401);
    }

    public function test_homestay_admin_scan_returns_data_without_storing_the_image(): void
    {
        $this->mockScan($this->qrData());

        $this->withHeaders($this->adminHeaders())
            ->post('/api/admin/cccd/scan-qr', ['cccd_qr_image' => $this->image(), 'guest_index' => 3, 'checkin_date' => '2026-12-01'])
            ->assertOk()
            ->assertJson([
                'scanned'        => true,
                'guest_index'    => 3,
                'data'           => ['cccd' => '087204016918', 'full_name' => 'Nguyễn Văn A'],
                'warnings'       => [],
                'birth_province' => 'Đồng Tháp',
                'age'            => 22,
                'under_age'      => false,
            ]);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_homestay_admin_unreadable_qr_is_not_a_request_error(): void
    {
        $this->mockScan(null);

        $response = $this->withHeaders($this->adminHeaders())
            ->post('/api/admin/cccd/scan-qr', ['cccd_qr_image' => $this->image()])
            ->assertOk()
            ->assertJson(['scanned' => false, 'data' => null, 'age' => null, 'under_age' => false]);

        $this->assertCount(1, $response->json('warnings'));
    }

    public function test_homestay_admin_invalid_number_returns_data_with_warning(): void
    {
        $this->mockScan($this->qrData(['dob' => '12/05/1999']));

        $response = $this->withHeaders($this->adminHeaders())
            ->post('/api/admin/cccd/scan-qr', ['cccd_qr_image' => $this->image()])
            ->assertOk()
            ->assertJson(['scanned' => true, 'data' => ['cccd' => '087204016918']]);

        $this->assertCount(1, $response->json('warnings'));
    }

    public function test_missing_image_returns_cccd_required(): void
    {
        $headers = $this->adminHeaders();

        $this->withHeaders($headers)->postJson('/api/admin/cccd/scan-qr')
            ->assertStatus(422)->assertJson(['code' => 'cccd_required', 'field' => 'cccd_qr_image']);
        $this->withHeaders($headers)->postJson('/api/admin/minihouse/cccd/scan-qr')
            ->assertStatus(422)->assertJson(['code' => 'cccd_required', 'field' => 'cccd_qr_image']);
    }

    public function test_minihouse_scan_maps_data_to_tenant_fields(): void
    {
        $this->mockScan($this->qrData());

        $this->withHeaders($this->adminHeaders())
            ->post('/api/admin/minihouse/cccd/scan-qr', ['cccd_qr_image' => $this->image()])
            ->assertOk()
            ->assertJson([
                'scanned'       => true,
                'warnings'      => [],
                'tenant_fields' => [
                    'fullname'            => 'Nguyễn Văn A',
                    'id_card_number'      => '087204016918',
                    'gender'              => 'nam',
                    'permanent_address'   => 'Đồng Tháp',
                    'date_of_birth'       => '2004-05-12',
                    'id_card_issued_date' => '2022-01-01',
                ],
            ]);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_minihouse_unreadable_qr_returns_null_tenant_fields(): void
    {
        $this->mockScan(null);

        $this->withHeaders($this->adminHeaders())
            ->post('/api/admin/minihouse/cccd/scan-qr', ['cccd_qr_image' => $this->image()])
            ->assertOk()
            ->assertJson(['scanned' => false, 'data' => null, 'tenant_fields' => null]);
    }
}

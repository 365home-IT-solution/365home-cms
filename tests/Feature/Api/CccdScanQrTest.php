<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Modules\Payment\App\Services\CccdScannerService;
use Tests\TestCase;

// Endpoint quét QR CCCD độc lập của app khách — không lưu ảnh, không đụng luồng front/back:
// POST /api/guest/cccd/scan-qr (vãng lai) và POST /api/cccd/scan-qr (đã đăng nhập).
class CccdScanQrTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Cache của test là cache thật của máy (config đọc CACHE_DRIVER) — chỉ xoá đúng key giới hạn
        // lượt quét theo IP, không flush cả cache.
        RateLimiter::clear('cccd-scan:ip:127.0.0.1');
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');
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

    /** @return array{0: Customer, 1: array<string, string>} */
    private function customerWithHeaders(array $attributes = []): array
    {
        $customer = Customer::create(array_merge([
            'fullname' => 'Khách Test',
            'phone'    => '09' . random_int(10000000, 99999999),
            'status'   => Customer::STATUS_ACTIVE,
        ], $attributes));

        return [$customer, ['Authorization' => 'Bearer ' . $customer->createToken('t')->plainTextToken, 'Accept' => 'application/json']];
    }

    // ── Khách vãng lai ───────────────────────────────────────────────────────

    public function test_guest_returns_scanned_data_without_storing_the_image(): void
    {
        $this->mockScan($this->qrData());

        $response = $this->post('/api/guest/cccd/scan-qr', ['cccd_qr_image' => $this->image(), 'guest_index' => 2, 'checkin_date' => '2026-12-01'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson([
                'scanned'        => true,
                'guest_index'    => 2,
                'data'           => ['cccd' => '087204016918', 'full_name' => 'Nguyễn Văn A', 'source' => 'qr'],
                'birth_province' => 'Đồng Tháp',
                'age'            => 22,
                'under_age'      => false,
            ]);

        $this->assertArrayNotHasKey('same_as_profile', $response->json());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_guest_missing_image_returns_cccd_required(): void
    {
        $this->postJson('/api/guest/cccd/scan-qr')
            ->assertStatus(422)
            ->assertJson(['code' => 'cccd_required', 'field' => 'cccd_qr_image']);
    }

    public function test_guest_unreadable_qr_returns_cccd_qr_unreadable(): void
    {
        $this->mockScan(null);

        $this->post('/api/guest/cccd/scan-qr', ['cccd_qr_image' => $this->image()], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJson(['code' => 'cccd_qr_unreadable', 'field' => 'cccd_qr_image']);
    }

    public function test_guest_number_not_matching_birth_year_returns_cccd_invalid(): void
    {
        $this->mockScan($this->qrData(['dob' => '12/05/1999']));

        $this->post('/api/guest/cccd/scan-qr', ['cccd_qr_image' => $this->image()], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJson(['code' => 'cccd_invalid']);
    }

    public function test_guest_under_age_is_reported_but_not_blocked(): void
    {
        $this->mockScan($this->qrData(['cccd' => '087215016918', 'dob' => '12/05/2015']));

        $this->post('/api/guest/cccd/scan-qr', ['cccd_qr_image' => $this->image()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['under_age' => true, 'min_age' => 16]);
    }

    // ── Khách đã đăng nhập ───────────────────────────────────────────────────

    public function test_logged_in_endpoint_requires_customer_token(): void
    {
        $this->postJson('/api/cccd/scan-qr')->assertStatus(401);
    }

    public function test_logged_in_scan_compares_with_profile(): void
    {
        $this->mockScan($this->qrData());
        [, $headers] = $this->customerWithHeaders(['cccd_data' => $this->qrData()]);

        $this->withHeaders($headers)->post('/api/cccd/scan-qr', ['cccd_qr_image' => $this->image()])
            ->assertOk()
            ->assertJson(['scanned' => true, 'data' => ['cccd' => '087204016918'], 'same_as_profile' => true, 'companion_id' => null]);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_logged_in_scan_finds_saved_companion(): void
    {
        $this->mockScan($this->qrData());
        [$customer, $headers] = $this->customerWithHeaders();
        $companion = $customer->companions()->create(['full_name' => 'Nguyễn Văn A', 'cccd_data' => $this->qrData()]);

        $this->withHeaders($headers)->post('/api/cccd/scan-qr', ['cccd_qr_image' => $this->image()])
            ->assertOk()
            ->assertJson(['same_as_profile' => null, 'companion_id' => $companion->id]);
    }

    public function test_logged_in_unreadable_qr_returns_cccd_qr_unreadable(): void
    {
        $this->mockScan(null);
        [, $headers] = $this->customerWithHeaders();

        $this->withHeaders($headers)->post('/api/cccd/scan-qr', ['cccd_qr_image' => $this->image()])
            ->assertStatus(422)
            ->assertJson(['code' => 'cccd_qr_unreadable']);
    }
}

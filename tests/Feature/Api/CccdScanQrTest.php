<?php

namespace Tests\Feature\Api;

use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Modules\Payment\App\Services\CccdScannerService;
use Tests\TestCase;

// POST /api/cccd/scan-qr — endpoint quét QR độc lập, không lưu ảnh, không đụng luồng front/back.
class CccdScanQrTest extends TestCase
{
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

    public function test_returns_scanned_data_without_storing_the_image(): void
    {
        $this->mockScan($this->qrData());

        $this->post('/api/cccd/scan-qr', ['cccd_qr_image' => $this->image(), 'guest_index' => 2, 'checkin_date' => '2026-12-01'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson([
                'scanned'        => true,
                'guest_index'    => 2,
                'data'           => ['cccd' => '087204016918', 'full_name' => 'Nguyễn Văn A', 'source' => 'qr'],
                'birth_province' => 'Đồng Tháp',
                'age'            => 22,
                'under_age'      => false,
            ]);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_missing_image_returns_cccd_required(): void
    {
        $this->postJson('/api/cccd/scan-qr')
            ->assertStatus(422)
            ->assertJson(['code' => 'cccd_required', 'field' => 'cccd_qr_image']);
    }

    public function test_unreadable_qr_returns_cccd_qr_unreadable(): void
    {
        $this->mockScan(null);

        $this->post('/api/cccd/scan-qr', ['cccd_qr_image' => $this->image()], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJson(['code' => 'cccd_qr_unreadable', 'field' => 'cccd_qr_image']);
    }

    public function test_number_not_matching_birth_year_returns_cccd_invalid(): void
    {
        $this->mockScan($this->qrData(['dob' => '12/05/1999']));

        $this->post('/api/cccd/scan-qr', ['cccd_qr_image' => $this->image()], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJson(['code' => 'cccd_invalid']);
    }

    public function test_under_age_is_reported_but_not_blocked(): void
    {
        $this->mockScan($this->qrData(['cccd' => '087215016918', 'dob' => '12/05/2015']));

        $this->post('/api/cccd/scan-qr', ['cccd_qr_image' => $this->image()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['under_age' => true, 'min_age' => 16]);
    }
}

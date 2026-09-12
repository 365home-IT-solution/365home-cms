<?php

namespace Tests\Feature\Minihouse;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Payment\App\Services\CccdScannerService;
use Tests\TestCase;

// Người dùng hỏi: popup "thêm nhanh Khách thuê" (Tenant::create() gọi qua
// ContractForm::miniTenantForm()) khi lưu có tự quét ngầm CCCD và điền lại hồ sơ không — đúng, xác
// nhận bằng test này: TenantObserver::saved() được đăng ký GLOBAL cho model Tenant (xem
// MinihouseServiceProvider::boot()) nên bất kỳ Tenant::create() nào (kể cả gọi trực tiếp như
// createOptionUsing() của popup, không qua trang Tạo khách thuê đầy đủ) đều tự kích hoạt quét.
class QuickTenantCccdAutoScanTest extends TestCase
{
    use DatabaseTransactions;

    public function test_creating_tenant_directly_with_cccd_photo_auto_fills_blank_fields(): void
    {
        Storage::fake('public');

        $path = UploadedFile::fake()->image('cccd-front.jpg')->store('minihouse/tenants', 'public');

        // Giả lập kết quả quét CCCD (không phụ thuộc dịch vụ OCR/QR thật) — đúng định dạng
        // CccdScannerService::scanImage() trả về, để CccdScanMapper::mapToTenantFields() map lại.
        $this->mock(CccdScannerService::class, function ($mock) {
            $mock->shouldReceive('scanImage')->andReturn([
                'full_name' => 'Trần Văn B',
                'cccd'      => '079099001122',
                'gender'    => 'Nam',
                'address'   => '10 Điện Biên Phủ, Hà Nội',
                'dob'       => '15/03/1998',
            ]);
        });

        // Mô phỏng ĐÚNG dữ liệu popup gửi lên khi bấm "Tạo" — chỉ điền họ tên tay, tải ảnh CCCD, để
        // trống các trường còn lại (đúng kịch bản nhân viên lười gõ tay, trông chờ quét tự điền).
        $tenant = Tenant::create([
            'fullname'       => 'Trần Văn B',
            'id_card_front'  => $path,
        ]);

        $tenant->refresh();

        $this->assertSame('079099001122', $tenant->id_card_number);
        $this->assertSame(Tenant::GENDER_MALE, $tenant->gender);
        $this->assertSame('10 Điện Biên Phủ, Hà Nội', $tenant->permanent_address);
        $this->assertSame('1998-03-15', $tenant->date_of_birth->toDateString());
    }

    public function test_manually_filled_fields_are_not_overwritten_by_scan(): void
    {
        Storage::fake('public');

        $path = UploadedFile::fake()->image('cccd-front.jpg')->store('minihouse/tenants', 'public');

        $this->mock(CccdScannerService::class, function ($mock) {
            $mock->shouldReceive('scanImage')->andReturn([
                'full_name' => 'Tên quét ra',
                'gender'    => 'Nữ',
            ]);
        });

        // Nhân viên đã tự chọn giới tính tay trong popup — quét ra khác thì KHÔNG được ghi đè lên
        // giá trị đã nhập (chỉ điền field đang TRỐNG, xem TenantObserver::scanAndFill()).
        $tenant = Tenant::create([
            'fullname'      => 'Trần Văn B',
            'gender'        => Tenant::GENDER_MALE,
            'id_card_front' => $path,
        ]);

        $tenant->refresh();

        $this->assertSame(Tenant::GENDER_MALE, $tenant->gender);
    }
}

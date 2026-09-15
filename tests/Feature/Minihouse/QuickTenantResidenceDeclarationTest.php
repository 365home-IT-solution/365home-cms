<?php

namespace Tests\Feature\Minihouse;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ResidenceDeclaration;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Người dùng hỏi: dữ liệu nhập ở popup "thêm nhanh Khách thuê" (ContractForm::miniTenantForm(), mở
// ngay lúc tạo Hợp đồng) đã đủ để hệ thống tự sinh "Khai báo lưu trú" hoàn chỉnh chưa — trước đây
// popup chỉ có 5 field (họ tên/SĐT/CCCD/ảnh 2 mặt), thiếu ngày sinh/giới tính/quốc tịch/loại giấy
// tờ/nơi thường trú so với 13 trường bắt buộc của ResidenceDeclaration::REQUIRED_FIELD_LABELS. Đã bổ
// sung các field đó vào popup + nối vào ResidenceDeclarationService::upsertFromTenant() (nationality/
// document_type ưu tiên đọc từ Tenant). Test này xác nhận: điền đủ dữ liệu như popup mới sẽ cho ra 1
// bản Khai báo lưu trú isDataComplete() = true ngay khi tạo hợp đồng, không cần bổ sung tay thêm gì.
class QuickTenantResidenceDeclarationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_contract_created_with_fully_filled_quick_tenant_yields_complete_residence_declaration(): void
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create([
            'zone_id' => $zone->id, 'name' => 'Toà X', 'address' => '12 Nguyễn Huệ',
            'province' => 'TP. Hồ Chí Minh', 'ward' => 'Phường Bến Nghé',
        ]);
        $room = Room::create(['building_id' => $building->id, 'code' => 'X-01', 'price' => 3000000, 'status' => Room::STATUS_EMPTY]);

        // Đúng những field popup "thêm nhanh Khách thuê" thu thập SAU khi bổ sung (không upload ảnh
        // CCCD — mô phỏng trường hợp không quét được/không có ảnh, phải tự nhập tay đầy đủ).
        $tenant = Tenant::create([
            'fullname' => 'Nguyễn Văn A',
            'phone' => '0912345678',
            'id_card_number' => '079123456789',
            'date_of_birth' => '1995-05-20',
            'gender' => Tenant::GENDER_MALE,
            'nationality' => 'VNM - Viet Nam',
            'document_type' => '1 - Thẻ CCCD',
            'permanent_address' => '45 Lê Lợi, Đồng Nai',
        ]);

        $contract = Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => now(), 'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
            'reason_for_stay' => '1 - Du lịch',
        ]);

        $declaration = ResidenceDeclaration::where('contract_id', $contract->id)->where('tenant_id', $tenant->id)->first();

        $this->assertNotNull($declaration, 'Expected ResidenceDeclaration to be auto-created when the contract is saved.');
        $this->assertTrue($declaration->isDataComplete(), 'Missing fields: ' . implode(', ', $declaration->missingRequiredFieldLabels()));
        $this->assertSame('VNM - Viet Nam', $declaration->nationality);
        $this->assertSame('1 - Thẻ CCCD', $declaration->document_type);
        $this->assertSame('45 Lê Lợi, Đồng Nai', $declaration->current_residence);
        $this->assertSame('X-01', $declaration->room_number);
        $this->assertSame('TP. Hồ Chí Minh', $declaration->province);
    }

    public function test_tenant_specific_nationality_overrides_default_on_sync(): void
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'Toà Y', 'address' => 'a', 'province' => 'HN', 'ward' => 'W']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'Y-01', 'price' => 3000000, 'status' => Room::STATUS_EMPTY]);

        $tenant = Tenant::create([
            'fullname' => 'John Smith', 'nationality' => 'USA - United States', 'document_type' => '4 - Hộ chiếu',
        ]);

        $contract = Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => now(), 'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);

        $declaration = ResidenceDeclaration::where('contract_id', $contract->id)->where('tenant_id', $tenant->id)->first();

        $this->assertSame('USA - United States', $declaration->nationality);
        $this->assertSame('4 - Hộ chiếu', $declaration->document_type);
    }
}

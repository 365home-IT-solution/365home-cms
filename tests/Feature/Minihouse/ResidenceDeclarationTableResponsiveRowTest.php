<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ResidenceDeclaration;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Cùng yêu cầu/lỗi đã gặp và cách xử lý như TenantTableResponsiveRowTest (xem ghi chú đầy đủ ở đó):
// dùng Tables\Columns\ViewColumn (KHÔNG phải Tables\Columns\Layout\*) để tránh bật
// Table::hasColumnsLayout() làm đổi HẲN cách render TOÀN BẢNG (ảnh hưởng cả desktop). Áp dụng lại
// đúng cơ chế đó cho bảng Khai báo lưu trú.
class ResidenceDeclarationTableResponsiveRowTest extends TestCase
{
    use DatabaseTransactions;

    private function makeDeclaration(): ResidenceDeclaration
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-04', 'status' => Room::STATUS_RENTED]);
        $tenant = Tenant::create(['fullname' => 'Khai Báo Test', 'phone' => '0933333333']);
        $contract = Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => now()->subMonth(), 'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);

        // Contract creation tự sinh 1 bản ResidenceDeclaration qua ResidenceDeclarationService (xem
        // QuickTenantResidenceDeclarationTest) — dọn sạch bản tự sinh đó rồi tạo tay 1 bản với đủ
        // full_name/cccd_number riêng để test không phụ thuộc vào logic tự sinh này.
        ResidenceDeclaration::where('contract_id', $contract->id)->delete();

        return ResidenceDeclaration::create([
            'contract_id' => $contract->id, 'tenant_id' => $tenant->id,
            'full_name' => 'Khai Báo Test',
            'cccd_number' => '079333333333',
            'checked_in_at' => now(),
        ]);
    }

    public function test_residence_declarations_list_page_renders_classic_table_markup_with_mobile_row_content(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);
        $this->makeDeclaration();

        $response = $this->actingAs($admin)->get('/minihouse-admin/residence-declarations');

        $response->assertOk();
        $html = $response->getContent();

        // Bảng cổ điển thật sự — không phải dạng div-list của chế độ columns-layout.
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<thead', $html);

        $response->assertSee('Khai Báo Test');
        $response->assertSee('R-04');
        $response->assertSee('079333333333');

        $this->assertStringContainsString('md:hidden', $html);
        $this->assertStringContainsString('hidden md:table-cell', $html);
    }
}

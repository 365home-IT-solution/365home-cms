<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Cùng yêu cầu/lỗi đã gặp và cách xử lý như TenantTableResponsiveRowTest (xem ghi chú đầy đủ ở đó):
// dùng Tables\Columns\ViewColumn (KHÔNG phải Tables\Columns\Layout\*) để tránh bật
// Table::hasColumnsLayout() làm đổi HẲN cách render TOÀN BẢNG (ảnh hưởng cả desktop). Áp dụng lại
// đúng cơ chế đó cho bảng Hợp đồng.
class ContractTableResponsiveRowTest extends TestCase
{
    use DatabaseTransactions;

    private function makeContract(): Contract
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-01', 'status' => Room::STATUS_RENTED]);
        $tenant = Tenant::create(['fullname' => 'Hợp Đồng Test', 'phone' => '0911111111']);

        return Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);
    }

    public function test_contracts_list_page_renders_classic_table_markup_with_mobile_row_content(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);
        $this->makeContract();

        $response = $this->actingAs($admin)->get('/minihouse-admin/contracts');

        $response->assertOk();
        $html = $response->getContent();

        // Bảng cổ điển thật sự — không phải dạng div-list của chế độ columns-layout.
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<thead', $html);

        $response->assertSee('Hợp Đồng Test');
        $response->assertSee('R-01');
        // Dòng gọn mobile GHÉP ngày bắt đầu/kết thúc lại 1 chuỗi ("dd/mm/yyyy - dd/mm/yyyy") — cột
        // desktop hiện 2 cột NGÀY RIÊNG, không bao giờ ra đúng chuỗi ghép này, nên chỉ có thể tới từ
        // dòng gọn mobile (ViewColumn), xác nhận cột đó thật sự render.
        $this->assertStringContainsString('01/01/2026 - 31/12/2026', $html);

        $this->assertStringContainsString('md:hidden', $html);
        $this->assertStringContainsString('hidden md:table-cell', $html);
    }
}

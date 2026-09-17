<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Cùng yêu cầu/lỗi đã gặp và cách xử lý như TenantTableResponsiveRowTest (xem ghi chú đầy đủ ở đó):
// dùng Tables\Columns\ViewColumn (KHÔNG phải Tables\Columns\Layout\*) để tránh bật
// Table::hasColumnsLayout() làm đổi HẲN cách render TOÀN BẢNG (ảnh hưởng cả desktop). Áp dụng lại
// đúng cơ chế đó cho bảng Hoá đơn — KHÔNG đụng tới cơ chế chặn xoá hoá đơn đã thanh toán
// (InvoiceTable::table()->actions()), chỉ thêm 1 cột ViewColumn + ->visibleFrom('md') cho cột cũ.
class InvoiceTableResponsiveRowTest extends TestCase
{
    use DatabaseTransactions;

    private function makeInvoice(): Invoice
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-02', 'status' => Room::STATUS_RENTED]);
        $tenant = Tenant::create(['fullname' => 'Hoá Đơn Test', 'phone' => '0922222222']);
        $contract = Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => now()->subMonth(), 'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);

        return Invoice::create([
            'contract_id' => $contract->id, 'month' => '2026-09-01',
            'room_price' => 3000000, 'total_amount' => 3000000, 'status' => Invoice::STATUS_UNPAID,
        ]);
    }

    public function test_invoices_list_page_renders_classic_table_markup_with_mobile_row_content(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);
        $this->makeInvoice();

        $response = $this->actingAs($admin)->get('/minihouse-admin/invoices');

        $response->assertOk();
        $html = $response->getContent();

        // Bảng cổ điển thật sự — không phải dạng div-list của chế độ columns-layout.
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<thead', $html);

        $response->assertSee('Hoá Đơn Test');
        $response->assertSee('R-02');
        // Dòng gọn mobile ghép tiền tố "Tháng " trước tháng hoá đơn — cột desktop "Tháng" chỉ hiện
        // đúng "09/2026" không có tiền tố này, nên chuỗi ghép chỉ có thể tới từ dòng gọn mobile.
        $this->assertStringContainsString('Tháng 09/2026', $html);

        $this->assertStringContainsString('md:hidden', $html);
        $this->assertStringContainsString('hidden md:table-cell', $html);
    }
}

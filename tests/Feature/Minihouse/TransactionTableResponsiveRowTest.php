<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Transaction;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Người dùng thấy bảng Thu Chi khó xem/phải cuộn ngang trên mobile. Áp dụng ĐÚNG pattern đã dùng
// cho TenantTable (xem TenantTableResponsiveRowTest) — Tables\Columns\ViewColumn (KHÔNG phải
// Tables\Columns\Layout\*) để bảng vẫn ở đúng chế độ <table> cổ điển, không bật
// Table::hasColumnsLayout() (đổi HẲN cách bảng render, ảnh hưởng cả desktop nếu dùng nhầm Layout\*).
class TransactionTableResponsiveRowTest extends TestCase
{
    use DatabaseTransactions;

    private function makeTransaction(): Transaction
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'Toà Test', 'address' => 'a']);

        return Transaction::create([
            'building_id'      => $building->id,
            'type'             => Transaction::TYPE_IN,
            'category'         => Transaction::CATEGORY_OPERATION,
            'amount'           => 1500000,
            'transaction_date' => now(),
            'note'             => 'Giao dịch test',
        ]);
    }

    public function test_transactions_list_page_renders_classic_table_markup_with_mobile_row_content(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);
        $this->makeTransaction();

        $response = $this->actingAs($admin)->get('/minihouse-admin/transactions');

        $response->assertOk();
        $html = $response->getContent();

        // Bảng cổ điển thật sự — không phải dạng div-list của chế độ columns-layout.
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<thead', $html);

        // Nội dung dòng gọn (mobile) VẪN có trong HTML (ẩn/hiện do CSS theo màn hình, không phải do
        // server không render) — vừa xác nhận đúng dữ liệu, vừa xác nhận cột ViewColumn hoạt động.
        $response->assertSee('Toà Test');
        $response->assertSee('Vận hành');

        // Xác nhận đúng CƠ CHẾ CSS ẩn/hiện theo breakpoint đang thật sự được áp dụng (không chỉ
        // "render ra được" mà còn đúng class Tailwind điều khiển hiển thị theo màn hình).
        $this->assertStringContainsString('md:hidden', $html); // cột dòng gọn: ẩn TỪ md trở lên (chỉ hiện < md)
        $this->assertStringContainsString('hidden md:table-cell', $html); // các cột cũ: ẩn dưới md, hiện TỪ md trở lên
    }
}

<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Người dùng thấy bảng Khu vực khó xem/phải cuộn ngang trên mobile. Áp dụng ĐÚNG pattern đã dùng
// cho TenantTable (xem TenantTableResponsiveRowTest) — Tables\Columns\ViewColumn (KHÔNG phải
// Tables\Columns\Layout\*) để bảng vẫn ở đúng chế độ <table> cổ điển, không bật
// Table::hasColumnsLayout() (đổi HẲN cách bảng render, ảnh hưởng cả desktop nếu dùng nhầm Layout\*).
class ZoneTableResponsiveRowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_zones_list_page_renders_classic_table_markup_with_mobile_row_content(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);

        Zone::create(['name' => 'Khu Vực Test ' . uniqid(), 'note' => 'Ghi chú test']);

        $response = $this->actingAs($admin)->get('/minihouse-admin/zones');

        $response->assertOk();
        $html = $response->getContent();

        // Bảng cổ điển thật sự — không phải dạng div-list của chế độ columns-layout.
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<thead', $html);

        // Nội dung dòng gọn (mobile) VẪN có trong HTML (ẩn/hiện do CSS theo màn hình, không phải do
        // server không render) — vừa xác nhận đúng dữ liệu, vừa xác nhận cột ViewColumn hoạt động.
        $response->assertSee('Khu Vực Test', false);
        $response->assertSee('Ghi chú test');

        // Xác nhận đúng CƠ CHẾ CSS ẩn/hiện theo breakpoint đang thật sự được áp dụng (không chỉ
        // "render ra được" mà còn đúng class Tailwind điều khiển hiển thị theo màn hình).
        $this->assertStringContainsString('md:hidden', $html); // cột dòng gọn: ẩn TỪ md trở lên (chỉ hiện < md)
        $this->assertStringContainsString('hidden md:table-cell', $html); // các cột cũ: ẩn dưới md, hiện TỪ md trở lên
    }
}

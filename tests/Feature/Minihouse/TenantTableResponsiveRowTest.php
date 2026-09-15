<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Người dùng thấy bảng Khách thuê khó xem/phải cuộn ngang trên mobile. Đã thử
// Tables\Columns\Layout\Split trước đó — PHÁT HIỆN: chỉ cần 1 cột kiểu Layout\* trong ->columns() là
// Table::hasColumnsLayout() bật true, đổi HẲN cách bảng render (mỗi dòng thành <div> tự do, KHÔNG
// còn <table>/<thead>/<tr>/<td> cổ điển nữa) — ảnh hưởng NGAY CẢ DESKTOP dù cột đó chỉ định
// ->hiddenFrom('md'), vì đây là thay đổi cấu trúc HTML TOÀN BẢNG, không phải riêng 1 cột. Đã revert.
// Lần này dùng Tables\Columns\ViewColumn (KHÁC namespace, vẫn là Column bình thường, KHÔNG bật
// hasColumnsLayout) — test dưới đây khẳng định chắc chắn bảng vẫn ở đúng chế độ <table> cổ điển,
// tránh lặp lại đúng lỗi đã gặp trước khi báo cho người dùng là "đã xong".
class TenantTableResponsiveRowTest extends TestCase
{
    use DatabaseTransactions;

    private function makeTenant(): Tenant
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-01', 'status' => Room::STATUS_RENTED]);

        return Tenant::create(['fullname' => 'Khách Test', 'phone' => '0900000000', 'id_card_number' => '079123456789', 'room_id' => $room->id, 'residence_declared' => false]);
    }

    public function test_tenants_list_page_renders_classic_table_markup_with_mobile_row_content(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);
        $this->makeTenant();

        $response = $this->actingAs($admin)->get('/minihouse-admin/tenants');

        $response->assertOk();
        $html = $response->getContent();

        // Bảng cổ điển thật sự — không phải dạng div-list của chế độ columns-layout.
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<thead', $html);

        // Nội dung dòng gọn (mobile) VẪN có trong HTML (ẩn/hiện do CSS theo màn hình, không phải do
        // server không render) — vừa xác nhận đúng dữ liệu, vừa xác nhận cột ViewColumn hoạt động.
        $response->assertSee('Khách Test');
        $response->assertSee('079123456789'); // cột CCCD/CMND desktop hiện đầy đủ số
        $response->assertSee('••6789'); // dòng gọn mobile ẩn bớt số CCCD, chỉ hiện 4 số cuối

        // Xác nhận đúng CƠ CHẾ CSS ẩn/hiện theo breakpoint đang thật sự được áp dụng (không chỉ
        // "render ra được" mà còn đúng class Tailwind điều khiển hiển thị theo màn hình).
        $this->assertStringContainsString('md:hidden', $html); // cột dòng gọn: ẩn TỪ md trở lên (chỉ hiện < md)
        $this->assertStringContainsString('hidden md:table-cell', $html); // các cột cũ: ẩn dưới md, hiện TỪ md trở lên
    }
}

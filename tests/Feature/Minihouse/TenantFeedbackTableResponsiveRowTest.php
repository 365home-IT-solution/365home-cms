<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\TenantFeedback;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Người dùng thấy bảng Phản hồi khách thuê khó xem/phải cuộn ngang trên mobile. Dùng đúng cơ chế đã
// áp dụng cho bảng Khách thuê (xem TenantTableResponsiveRowTest) — Tables\Columns\ViewColumn (KHÁC
// hẳn Tables\Columns\Layout\*, không bật Table::hasColumnsLayout()) — test dưới đây khẳng định chắc
// chắn bảng vẫn ở đúng chế độ <table> cổ điển, tránh lặp lại đúng lỗi đã gặp trước khi báo cho
// người dùng là "đã xong".
class TenantFeedbackTableResponsiveRowTest extends TestCase
{
    use DatabaseTransactions;

    private function makeFeedback(): TenantFeedback
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'Toà C', 'address' => 'a']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-09', 'status' => Room::STATUS_RENTED]);

        return TenantFeedback::create([
            'room_id'     => $room->id,
            'tenant_name' => 'Khách Phản Hồi',
            'rating'      => 4,
            'content'     => 'Phòng sạch sẽ, nhân viên nhiệt tình.',
            'is_reviewed' => false,
        ]);
    }

    public function test_tenant_feedbacks_list_page_renders_classic_table_markup_with_mobile_row_content(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);
        $this->makeFeedback();

        $response = $this->actingAs($admin)->get('/minihouse-admin/tenant-feedbacks');

        $response->assertOk();
        $html = $response->getContent();

        // Bảng cổ điển thật sự — không phải dạng div-list của chế độ columns-layout.
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<thead', $html);

        // Nội dung dòng gọn (mobile) VẪN có trong HTML (ẩn/hiện do CSS theo màn hình, không phải do
        // server không render) — vừa xác nhận đúng dữ liệu, vừa xác nhận cột ViewColumn hoạt động.
        $response->assertSee('Khách Phản Hồi');
        $response->assertSee('R-09');

        // Xác nhận đúng CƠ CHẾ CSS ẩn/hiện theo breakpoint đang thật sự được áp dụng (không chỉ
        // "render ra được" mà còn đúng class Tailwind điều khiển hiển thị theo màn hình).
        $this->assertStringContainsString('md:hidden', $html); // cột dòng gọn: ẩn TỪ md trở lên (chỉ hiện < md)
        $this->assertStringContainsString('hidden md:table-cell', $html); // các cột cũ: ẩn dưới md, hiện TỪ md trở lên
    }
}

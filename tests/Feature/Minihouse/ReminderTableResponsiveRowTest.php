<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Reminder;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Cùng yêu cầu/lỗi đã gặp và cách xử lý như TenantTableResponsiveRowTest (xem ghi chú đầy đủ ở đó):
// dùng Tables\Columns\ViewColumn (KHÔNG phải Tables\Columns\Layout\*) để tránh bật
// Table::hasColumnsLayout() làm đổi HẲN cách render TOÀN BẢNG (ảnh hưởng cả desktop). Áp dụng lại
// đúng cơ chế đó cho bảng Nhắc việc.
class ReminderTableResponsiveRowTest extends TestCase
{
    use DatabaseTransactions;

    private function makeReminder(): Reminder
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-03', 'status' => Room::STATUS_RENTED]);

        return Reminder::create([
            'title' => 'Nhắc Việc Test',
            'type' => Reminder::TYPE_PAYMENT,
            'remind_date' => now()->addDays(3),
            'room_id' => $room->id,
            'is_done' => false,
        ]);
    }

    public function test_reminders_list_page_renders_classic_table_markup_with_mobile_row_content(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);
        $this->makeReminder();

        $response = $this->actingAs($admin)->get('/minihouse-admin/reminders');

        $response->assertOk();
        $html = $response->getContent();

        // Bảng cổ điển thật sự — không phải dạng div-list của chế độ columns-layout.
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<thead', $html);

        $response->assertSee('Nhắc Việc Test');
        // Dòng gọn mobile ghép nhãn loại + phòng bằng dấu chấm giữa ("Nhắc đóng tiền · R-03") — cột
        // desktop hiện 2 cột RIÊNG (Loại / Phòng), không bao giờ ra đúng chuỗi ghép này.
        $this->assertStringContainsString('Nhắc đóng tiền', $html);
        $this->assertStringContainsString('R-03', $html);

        $this->assertStringContainsString('md:hidden', $html);
        $this->assertStringContainsString('hidden md:table-cell', $html);
    }
}

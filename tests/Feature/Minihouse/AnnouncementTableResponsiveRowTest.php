<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Announcement;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Cùng cơ chế responsive đã áp dụng cho TenantTable (xem TenantTableResponsiveRowTest) — dùng
// Tables\Columns\ViewColumn (KHÔNG PHẢI Tables\Columns\Layout\*) để tránh bật
// Table::hasColumnsLayout() (đổi HẲN cách bảng render, ảnh hưởng cả desktop). Test này khẳng định
// bảng Thông báo vẫn ở đúng chế độ <table> cổ điển sau khi thêm dòng gọn cho mobile.
class AnnouncementTableResponsiveRowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_announcements_list_page_renders_classic_table_markup_with_mobile_row_content(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);

        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'Toà Thông Báo ' . uniqid(), 'address' => 'a']);

        Announcement::create([
            'building_id' => $building->id,
            'title' => 'Thông Báo Cắt Nước Test',
            'body' => 'Nội dung',
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get('/minihouse-admin/announcements');

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<thead', $html);

        $response->assertSee('Thông Báo Cắt Nước Test');

        $this->assertStringContainsString('md:hidden', $html);
        $this->assertStringContainsString('hidden md:table-cell', $html);
    }
}

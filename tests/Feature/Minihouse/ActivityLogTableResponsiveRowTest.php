<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\ActivityLog;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Cùng cơ chế responsive đã áp dụng cho TenantTable (xem TenantTableResponsiveRowTest) — dùng
// Tables\Columns\ViewColumn (KHÔNG PHẢI Tables\Columns\Layout\*) để tránh bật
// Table::hasColumnsLayout() (đổi HẲN cách bảng render, ảnh hưởng cả desktop). Test này khẳng định
// bảng Nhật ký hoạt động vẫn ở đúng chế độ <table> cổ điển sau khi thêm dòng gọn cho mobile.
class ActivityLogTableResponsiveRowTest extends TestCase
{
    use DatabaseTransactions;

    private function makeActivityLog(): ActivityLog
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'Toà Log ' . uniqid(), 'address' => 'a']);

        return ActivityLog::create([
            'building_id' => $building->id,
            'user_name' => 'Nhân Viên Test',
            'action' => ActivityLog::ACTION_CREATED,
            'subject_type' => 'Modules\\Minihouse\\App\\Models\\Tenant',
            'subject_id' => 1,
            'subject_label' => 'Khách thuê Nguyễn Văn Test',
        ]);
    }

    public function test_activity_logs_list_page_renders_classic_table_markup_with_mobile_row_content(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);
        $this->makeActivityLog();

        $response = $this->actingAs($admin)->get('/minihouse-admin/activity-logs');

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<thead', $html);

        $response->assertSee('Khách thuê Nguyễn Văn Test');
        $response->assertSee('Nhân Viên Test');

        $this->assertStringContainsString('md:hidden', $html);
        $this->assertStringContainsString('hidden md:table-cell', $html);
    }
}

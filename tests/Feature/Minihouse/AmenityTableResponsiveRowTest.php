<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Amenity;
use Tests\TestCase;

// Cùng cơ chế responsive đã áp dụng cho TenantTable (xem TenantTableResponsiveRowTest) — dùng
// Tables\Columns\ViewColumn (KHÔNG PHẢI Tables\Columns\Layout\*) để tránh bật
// Table::hasColumnsLayout() (đổi HẲN cách bảng render, ảnh hưởng cả desktop). Test này khẳng định
// bảng Tiện ích vẫn ở đúng chế độ <table> cổ điển sau khi thêm dòng gọn cho mobile.
class AmenityTableResponsiveRowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_amenities_list_page_renders_classic_table_markup_with_mobile_row_content(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);
        Amenity::create(['name' => 'Wifi Miễn Phí Test']);

        $response = $this->actingAs($admin)->get('/minihouse-admin/amenities');

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<thead', $html);

        $response->assertSee('Wifi Miễn Phí Test');

        $this->assertStringContainsString('md:hidden', $html);
        $this->assertStringContainsString('hidden md:table-cell', $html);
    }
}

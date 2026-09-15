<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\AssetType;
use Tests\TestCase;

// Cùng cơ chế responsive đã áp dụng cho TenantTable (xem TenantTableResponsiveRowTest) — dùng
// Tables\Columns\ViewColumn (KHÔNG PHẢI Tables\Columns\Layout\*) để tránh bật
// Table::hasColumnsLayout(). Test này khẳng định bảng Loại tài sản (module mới thêm 2026-09-11,
// trước đó CHƯA có test nào) vẫn ở đúng chế độ <table> cổ điển sau khi thêm dòng gọn cho mobile.
class AssetTypeTableResponsiveRowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_asset_types_list_page_renders_classic_table_markup_with_mobile_row_content(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);
        AssetType::create(['name' => 'May Lanh Test']);

        $response = $this->actingAs($admin)->get('/minihouse-admin/asset-types');

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<thead', $html);

        $response->assertSee('May Lanh Test');

        $this->assertStringContainsString('md:hidden', $html);
        $this->assertStringContainsString('hidden md:table-cell', $html);
    }
}

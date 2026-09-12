<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// Người dùng thấy bảng Người dùng (MiniHouse) khó xem/phải cuộn ngang trên mobile. Áp dụng ĐÚNG
// pattern đã dùng cho TenantTable (xem TenantTableResponsiveRowTest) — Tables\Columns\ViewColumn
// (KHÔNG phải Tables\Columns\Layout\*) để bảng vẫn ở đúng chế độ <table> cổ điển, không bật
// Table::hasColumnsLayout() (đổi HẲN cách bảng render, ảnh hưởng cả desktop nếu dùng nhầm Layout\*).
class UserTableResponsiveRowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_users_list_page_renders_classic_table_markup_with_mobile_row_content(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);

        $user = User::create([
            'fullname' => 'Nhân Viên Test',
            'email'    => 'nv_test_' . uniqid() . '@example.com',
            'phone'    => '0911111111',
            'password' => Hash::make('password'),
        ]);
        // UserResource::getEloquentQuery() (Modules\Minihouse\App\Support\MinihousePermissions::
        // scopeToMinihouseUsers()) chỉ liệt kê tài khoản có quyền 'access_minihouse' (trực tiếp hoặc
        // qua role) hoặc super_admin — thiếu dòng này thì tài khoản test không hiện trong danh sách.
        $user->givePermissionTo('access_minihouse');

        $response = $this->actingAs($admin)->get('/minihouse-admin/users');

        $response->assertOk();
        $html = $response->getContent();

        // Bảng cổ điển thật sự — không phải dạng div-list của chế độ columns-layout.
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<thead', $html);

        // Nội dung dòng gọn (mobile) VẪN có trong HTML (ẩn/hiện do CSS theo màn hình, không phải do
        // server không render) — vừa xác nhận đúng dữ liệu, vừa xác nhận cột ViewColumn hoạt động.
        $response->assertSee('Nhân Viên Test');
        $response->assertSee('0911111111');

        // Xác nhận đúng CƠ CHẾ CSS ẩn/hiện theo breakpoint đang thật sự được áp dụng (không chỉ
        // "render ra được" mà còn đúng class Tailwind điều khiển hiển thị theo màn hình).
        $this->assertStringContainsString('md:hidden', $html); // cột dòng gọn: ẩn TỪ md trở lên (chỉ hiện < md)
        $this->assertStringContainsString('hidden md:table-cell', $html); // các cột cũ: ẩn dưới md, hiện TỪ md trở lên
    }
}

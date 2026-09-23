<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Tenant;
use Tests\TestCase;

// Admin đặt/đổi mật khẩu Portal HỘ khách thuê (TenantForm/TenantController) — trước đây 'password'
// CHỈ ghi được qua đúng 1 nơi (khách tự đặt sau khi đăng nhập OTP), giờ thêm đường admin. Khoá lại:
// (1) admin tạo tài khoản kèm mật khẩu login được ngay, (2) admin đổi mật khẩu thì mật khẩu CŨ không
// còn dùng được nữa, (3) để trống field password khi sửa = GIỮ NGUYÊN (không vô tình xoá mất mật
// khẩu khách đã có), (4) password không bao giờ lộ ra response API.
class TenantPasswordManagementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_create_tenant_with_password_and_tenant_can_login(): void
    {
        $admin = User::role('super_admin')->first();
        $token = $admin->createToken('t')->plainTextToken;
        $phone = '09' . random_int(10000000, 99999999);

        $create = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/admin/minihouse/tenants', [
                'fullname' => 'Khách A', 'phone' => $phone, 'password' => 'matkhau123',
            ]);

        $create->assertCreated();
        $create->assertJsonMissingPath('data.password');

        $login = $this->postJson('/api/minihouse/portal/login/password', [
            'phone' => $phone, 'password' => 'matkhau123',
        ]);
        $login->assertOk();
        $login->assertJsonStructure(['token', 'tenant' => ['id', 'fullname', 'phone']]);
    }

    public function test_admin_can_change_password_and_old_one_stops_working(): void
    {
        $admin = User::role('super_admin')->first();
        $token = $admin->createToken('t')->plainTextToken;
        $phone = '09' . random_int(10000000, 99999999);
        $tenant = Tenant::create(['fullname' => 'Khách B', 'phone' => $phone, 'password' => 'cumatkhau']);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->patchJson("/api/admin/minihouse/tenants/{$tenant->id}", ['password' => 'matkhaumoi'])
            ->assertOk();

        $this->postJson('/api/minihouse/portal/login/password', ['phone' => $phone, 'password' => 'cumatkhau'])
            ->assertStatus(422);

        $this->postJson('/api/minihouse/portal/login/password', ['phone' => $phone, 'password' => 'matkhaumoi'])
            ->assertOk();
    }

    public function test_blank_password_on_update_keeps_existing_password(): void
    {
        $admin = User::role('super_admin')->first();
        $token = $admin->createToken('t')->plainTextToken;
        $phone = '09' . random_int(10000000, 99999999);
        $tenant = Tenant::create(['fullname' => 'Khách C', 'phone' => $phone, 'password' => 'giunguyen123']);

        // Gửi password rỗng (VD form gửi nguyên state, ô mật khẩu để trống) — không được xoá mất.
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->patchJson("/api/admin/minihouse/tenants/{$tenant->id}", [
                'fullname' => 'Khách C (đổi tên)', 'password' => '',
            ])
            ->assertOk();

        $this->postJson('/api/minihouse/portal/login/password', ['phone' => $phone, 'password' => 'giunguyen123'])
            ->assertOk();

        $this->assertSame('Khách C (đổi tên)', $tenant->fresh()->fullname);
    }
}

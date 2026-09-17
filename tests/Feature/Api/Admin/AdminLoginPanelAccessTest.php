<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// User::factory() (database/factories/UserFactory.php) dùng key 'name' — cột không tồn tại trên
// bảng users (dùng 'fullname'), lỗi từ trước không liên quan gì tới thay đổi này. Dùng thẳng
// User::create() giống mọi test MiniHouse khác trong dự án để tránh factory hỏng đó.

// POST /api/admin/login dùng CHUNG 1 endpoint cho cả panel Homestay ('admin') và MiniHouse
// ('minihouse-admin') — FE cần trường "access" trong response để biết tài khoản vừa đăng nhập được
// dùng cho panel nào (đặc biệt super_admin đăng nhập được cả hai). Test này khoá lại đúng giá trị
// "access.homestay"/"access.minihouse" cho 3 kiểu tài khoản thực tế, dùng ĐÚNG
// User::canAccessPanel() (không tự suy luận lại logic riêng ở test).
class AdminLoginPanelAccessTest extends TestCase
{
    use DatabaseTransactions;

    private function loginAndGetAccess(User $user): array
    {
        $response = $this->postJson('/api/admin/login', [
            'email'    => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();

        return $response->json('user.access');
    }

    private function makeUser(?string $partnerId = null): User
    {
        return User::create([
            'email'      => 'test-' . uniqid() . '@example.com',
            'fullname'   => 'Test User',
            'password'   => Hash::make('password'),
            'partner_id' => $partnerId,
        ]);
    }

    public function test_super_admin_can_access_both_panels(): void
    {
        $user = $this->makeUser();
        $user->assignRole(config('filament-shield.super_admin.name'));

        $access = $this->loginAndGetAccess($user);

        $this->assertTrue($access['homestay']);
        $this->assertTrue($access['minihouse']);
    }

    public function test_staff_with_only_access_minihouse_permission_reports_minihouse_true(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'access_minihouse', 'guard_name' => 'web']);
        $role       = Role::firstOrCreate(['name' => 'Quản lý MiniHouse (test)', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $user = $this->makeUser();
        $user->assignRole($role);

        $access = $this->loginAndGetAccess($user);

        $this->assertTrue($access['minihouse']);
    }

    public function test_staff_without_access_minihouse_permission_reports_minihouse_false(): void
    {
        $role = Role::firstOrCreate(['name' => 'Nhân viên Homestay (test)', 'guard_name' => 'web']);

        $user = $this->makeUser();
        $user->assignRole($role);

        $access = $this->loginAndGetAccess($user);

        $this->assertTrue($access['homestay']);
        $this->assertFalse($access['minihouse']);
    }
}

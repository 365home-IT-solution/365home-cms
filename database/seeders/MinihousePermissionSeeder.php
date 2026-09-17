<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Minihouse\App\Support\MinihousePermissions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Seeder độc lập cho quyền panel MiniHouse (/minihouse-admin) — chạy tay 1 lần trên mỗi môi trường
// giống AuditLogPermissionSeeder, KHÔNG gọi trong DatabaseSeeder (seeder đó chỉ chạy khi DB rỗng,
// còn quyền mới này cần có mặt ở CẢ môi trường đã có dữ liệu thật): php artisan db:seed
// --class=MinihousePermissionSeeder
//
// MiniHouse dùng CHUNG App\Models\User/Spatie Permission với Home (không tách tài khoản riêng) —
// user nào được cấp các quyền này (trực tiếp hoặc qua vai trò) mới đăng nhập được vào
// /minihouse-admin, xem App\Models\User::canAccessPanel().
class MinihousePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Danh sách quyền lấy từ MinihousePermissions — NGUỒN DUY NHẤT, dùng chung với
        // Modules\Minihouse\App\Filament\Resources\RoleResource (tạo/sửa vai trò ngay trong panel
        // MiniHouse) để 2 nơi không lệch nhau khi thêm Resource mới.
        $permissions = MinihousePermissions::all();

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Vai trò mặc định "Quản lý MiniHouse" — được cấp sẵn toàn bộ quyền trên, gán cho user nào
        // cần quản lý cho thuê theo tháng mà không cần cấp quyền super_admin của Home.
        $role = Role::firstOrCreate(['name' => 'Quản lý MiniHouse', 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        $this->command?->info('MinihousePermissionSeeder: đã tạo ' . count($permissions) . ' quyền + vai trò "Quản lý MiniHouse".');
    }
}

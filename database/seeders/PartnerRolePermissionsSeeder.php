<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PartnerRolePermissionsSeeder extends Seeder
{
    // Role 'partner'/'employee' mới tạo không tự có quyền nào (Filament/Shield ẩn hết menu nếu
    // role không có permission trên resource đó) — dẫn tới đối tác đăng nhập chỉ thấy vài trang
    // không được Shield bảo vệ (khai báo lưu trú, khách vãng lai, tỉnh thành, thông báo app...).
    // Seeder này cấp đủ quyền cho role 'partner' (dùng trực tiếp cho tài khoản chủ đối tác) và
    // cho role 'employee' — role 'employee' ở đây CHỈ dùng làm MẪU quyền để PartnerSeeder copy
    // sang role riêng của từng đối tác (vd "Nhân viên - Đối tác Golden Bee", created_by = chủ đối
    // tác), không gán trực tiếp cho tài khoản nào — vì role có created_by=null sẽ vô hình/không
    // gỡ được với đối tác trong UserResource (Select role ở đó lọc theo created_by = user hiện tại).
    private const PARTNER_RESOURCES = [
        'category',            // Chi nhánh
        'product',              // Phòng
        'room::type',           // Danh mục Phòng
        'room::amenity',        // Tiện ích Phòng
        'room::amenity::assign', // Gán Tiện Ích Phòng
        // 'room::image' (Ảnh Phòng) KHÔNG đưa vào đây — RoomImageResource hard-code
        // canViewAny()/canCreate()/... chỉ cho super_admin (không dựa vào permission), nên cấp
        // quyền ở đây cũng không có tác dụng.
        'room::service',        // Dịch vụ Phòng
        'room::special',        // Điểm Đặc Biệt
        // 'room::housekeeping' (Kiểm tra dọn phòng) KHÔNG đưa vào đây — RoomHousekeepingResource
        // hard-code canViewAny() chỉ cho super_admin (không dựa vào permission), nên cấp quyền ở
        // đây cũng không có tác dụng.
        'coupon',
        'promotion',
        'order',
        'book',
        'access::code',
        'ttlock::account',
        'manual::lock::password',
        'addition::service',
        'tag',
        'post',
        'audit::log',
        'user',                 // Tài khoản nhân viên của chính đối tác (trang này tạo đồng thời
                                // User + hồ sơ Employee liên kết) — đã lọc theo partner_id
        'user::branch::permission',
        'shield::role',          // Đối tác tự tạo role phụ cho nhân viên (đã lọc theo created_by)
    ];

    // Nhân viên: thao tác vận hành hằng ngày, không quản lý tài khoản/role/audit log của đối tác.
    private const EMPLOYEE_RESOURCES = [
        'category',
        'product',
        'room::type',
        'room::amenity',
        'room::amenity::assign',
        'room::service',
        'room::special',
        // 'room::housekeeping' KHÔNG đưa vào đây — xem ghi chú ở PARTNER_RESOURCES phía trên.
        'coupon',
        'promotion',
        'order',
        'book',
        'access::code',
        'addition::service',
        'tag',
        'post',
    ];

    private const EXTRA_PAGES = [
        'page_Dashboard',
        'page_ManageGeneral',
        'page_MyProfilePage',
    ];

    public function run(): void
    {
        $this->syncRolePermissions('partner', self::PARTNER_RESOURCES);
        $this->syncRolePermissions('employee', self::EMPLOYEE_RESOURCES);
    }

    private function syncRolePermissions(string $roleName, array $resources): void
    {
        $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

        if (! $role) {
            return;
        }

        $allNames = Permission::pluck('name');

        $matched = $allNames->filter(function (string $name) use ($resources) {
            foreach ($resources as $resource) {
                if ($name === $resource
                    || str_ends_with($name, '_' . $resource)
                    || str_ends_with($name, '_any_' . $resource)
                ) {
                    return true;
                }
            }

            return false;
        })->merge(array_intersect(self::EXTRA_PAGES, $allNames->toArray()));

        $role->syncPermissions($matched->values()->all());
    }
}

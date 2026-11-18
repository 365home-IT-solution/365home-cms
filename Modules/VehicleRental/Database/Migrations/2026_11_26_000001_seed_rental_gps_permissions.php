<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Modules\Motrak\App\Support\MotrakAccess;
use Modules\VehicleRental\App\Support\VehicleRentalAccess;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

// Tạo QUYỀN của module Thuê xe + GPS để chọn được ở CMS → Phân quyền → Vai trò (tab "Custom"), và 2 vai trò mẫu cho nhân sự (chỉ THÊM quyền, không bao giờ thu hồi):
//  - "Nhân viên thuê xe": vận hành quầy (xem + vận hành đơn, duyệt giấy tờ, lịch xe, cảnh báo GPS).
//  - "Quản lý GPS": xem dữ liệu GPS.
// Super Admin luôn có mọi quyền. Chạy lại an toàn (idempotent).
return new class extends Migration
{
    public function up(): void
    {
        foreach ([...VehicleRentalAccess::PERMISSIONS, ...MotrakAccess::PERMISSIONS] as $name => $label) {
            Permission::findOrCreate($name, 'web');
        }

        $staff = Role::firstOrCreate(['name' => (string) config('vehiclerental.staff_role', 'Nhân viên thuê xe'), 'guard_name' => 'web']);
        $staff->givePermissionTo(VehicleRentalAccess::STAFF_PERMISSIONS);

        $gps = Role::firstOrCreate(['name' => (string) config('motrak.viewer_role', 'Quản lý GPS'), 'guard_name' => 'web']);
        $gps->givePermissionTo('view_gps');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Không xoá quyền / vai trò: có thể đã được gán cho tài khoản.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    // Dữ liệu tạo trước khi có khái niệm "đối tác" (partner_id null) sẽ được gom vào 1 đối tác
    // mặc định, để không "biến mất" khỏi tầm nhìn của tài khoản đối tác/nhân viên sau khi bật
    // lọc theo partner_id. Super_admin không bị ảnh hưởng (luôn xem được toàn bộ).
    public function up(): void
    {
        $hasUnassignedData = DB::table('users')->whereNull('partner_id')->exists()
            || DB::table('employees')->whereNull('partner_id')->exists()
            || DB::table('categories')->where('category_type', 'product')->whereNull('partner_id')->exists()
            || DB::table('products')->whereNull('partner_id')->exists();

        if (! $hasUnassignedData) {
            return;
        }

        $superAdminRoleId = DB::table('roles')->where('name', 'super_admin')->value('id');
        $superAdminUserIds = $superAdminRoleId
            ? DB::table('model_has_roles')
                ->where('role_id', $superAdminRoleId)
                ->where('model_type', \App\Models\User::class)
                ->pluck('model_id')
                ->toArray()
            : [];

        $createdBy = DB::table('users')->where('email', 'support@goldenbeeltd.vn')->value('id');

        // Dữ liệu tạo trước khi có khái niệm "đối tác" CHÍNH LÀ chi nhánh/phòng của nền tảng
        // 365Home (không phải 1 đối tác thứ 3 thật), nên gán thẳng tên "365Home" thay vì tên tạm
        // "Đối tác mặc định". KHÔNG gán verification_status/is_platform_partner ở ĐÂY — 2 cột này
        // do các migration SAU (2026_07_11_140001 / 2026_07_13_120001) mới tạo ra; trên database
        // hoàn toàn mới (đang chạy migration này lần đầu, đúng thứ tự ngày), 2 cột đó CHƯA TỒN TẠI
        // — gán ở đây sẽ lỗi "Column not found" (đã kiểm chứng thực tế). Việc đặt approved +
        // is_platform_partner=true cho đối tác này được dời qua đúng migration tạo ra
        // is_platform_partner (2026_07_13_120001), chạy sau khi cả 2 cột đã chắc chắn tồn tại.
        $partnerId = (string) Str::uuid();
        DB::table('partners')->insert([
            'id'         => $partnerId,
            'name'       => '365Home',
            'status'     => true,
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')
            ->whereNull('partner_id')
            ->when(! empty($superAdminUserIds), fn ($q) => $q->whereNotIn('id', $superAdminUserIds))
            ->update(['partner_id' => $partnerId]);

        DB::table('employees')->whereNull('partner_id')->update(['partner_id' => $partnerId]);
        DB::table('categories')->where('category_type', 'product')->whereNull('partner_id')->update(['partner_id' => $partnerId]);
        DB::table('products')->whereNull('partner_id')->update(['partner_id' => $partnerId]);
        DB::table('orders')->whereNull('partner_id')->update(['partner_id' => $partnerId]);
        DB::table('coupons')->whereNull('partner_id')->update(['partner_id' => $partnerId]);
        DB::table('promotions')->whereNull('partner_id')->update(['partner_id' => $partnerId]);
        DB::table('access_codes')->whereNull('partner_id')->update(['partner_id' => $partnerId]);
        DB::table('ttlock_accounts')->whereNull('partner_id')->update(['partner_id' => $partnerId]);
        DB::table('audit_logs')->whereNull('partner_id')->update(['partner_id' => $partnerId]);
    }

    public function down(): void
    {
        // Không hoàn tác — việc gán lại partner_id = null hàng loạt rủi ro mất thông tin phân loại
        // đã làm thủ công sau khi migrate. Nếu cần rollback, xoá partner mặc định bằng tay.
    }
};

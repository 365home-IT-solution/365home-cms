<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Tín hiệu để phân biệt "cài đặt mới hoàn toàn" (database rỗng) với "đã phục hồi dữ liệu
        // THẬT" (backup của chính dự án này, hoặc backup thật của người khác lấy code về dùng) —
        // bảng `users` chắc chắn rỗng ở 1 cài đặt mới, và chắc chắn KHÔNG rỗng ở bất kỳ hệ thống
        // thật nào đã có người dùng thật. Dựa vào đúng 1 tín hiệu này để "php artisan db:seed" tự
        // chạy đúng NGAY LẬP TỨC ở cả 2 trường hợp bằng 1 lệnh duy nhất — không cần biết trước
        // đang ở trường hợp nào, không cần chọn tay từng seeder.
        $isFreshInstall = DB::table('users')->count() === 0;

        // Core: roles/permissions — CHỈ tạo/gán cấu trúc quyền hạn, không sinh ra bất kỳ tài
        // khoản/dữ liệu nghiệp vụ nào — an toàn chạy ở MỌI trường hợp (cài mới lẫn dữ liệu thật).
        $this->call([
            RolesAndPermissionsSeeder::class,
        ]);

        Artisan::call('shield:generate --all');

        // Cấp quyền cho role partner/employee (phải chạy sau shield:generate vì permission
        // chỉ tồn tại sau khi lệnh này chạy xong) — cũng chỉ đụng tới quyền hạn, không tạo dữ liệu.
        $this->call([
            PartnerRolePermissionsSeeder::class,
        ]);

        // Danh mục tỉnh/thành + phường/xã cho tính năng Khai báo lưu trú — dữ liệu tham chiếu
        // dùng chung (không phải dữ liệu ảo/demo), cần cho MỌI trường hợp.
        $this->call([
            TbltAddressSeeder::class,
        ]);

        if (! $isFreshInstall) {
            $this->command?->info('DatabaseSeeder: phát hiện database đã có dữ liệu thật (bảng users không rỗng) — bỏ qua toàn bộ seeder demo (tài khoản mẫu, đối tác mẫu, danh mục/thiết lập mẫu của "365Home") để không tạo dữ liệu ảo đè lên dữ liệu thật.');

            return;
        }

        // ─────────────────────────────────────────────────────────────────────────────────────
        // Từ đây trở xuống CHỈ chạy khi database RỖNG HOÀN TOÀN (cài đặt mới/máy dev) — toàn bộ
        // là tài khoản/dữ liệu DEMO phục vụ phát triển, không phù hợp để chạy lên dữ liệu thật.
        // ─────────────────────────────────────────────────────────────────────────────────────

        // Tài khoản admin mặc định + user demo cho từng role.
        $this->call([
            UsersTableSeeder::class,
        ]);

        // Đối tác mẫu + tài khoản chủ đối tác/nhân viên cho từng đối tác.
        $this->call([
            PartnerSeeder::class,
        ]);

        // Dữ liệu mẫu ban đầu cho website "365Home" (chi nhánh/phòng/khung giờ/thiết lập...).
        $this->call([
            BusinessSeeder::class,
            CategorySeeder::class,
            TimeSlotSeeder::class,
            ComponentSeeder::class,
            SettingsSeeder::class,
            PaymentConfigSeeder::class,
        ]);
    }
}

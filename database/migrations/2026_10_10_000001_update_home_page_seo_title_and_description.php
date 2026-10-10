<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// SEO audit trang chủ: title "Trang chủ - Đặt phòng cùng 365Home" không chứa từ khoá chính, meta
// description chỉ 104 ký tự. Chỉ cập nhật khi giá trị VẪN là bản cũ — nếu admin đã tự sửa trong
// Trang > Pages thì giữ nguyên, không ghi đè.
return new class extends Migration
{
    private const OLD_TITLE = 'Trang chủ - Đặt phòng cùng 365Home';
    // Cột seo_title là varchar(60), seo_description là varchar(160) — 2 giá trị mới nằm trong giới hạn đó.
    private const NEW_TITLE = 'Homestay Cần Thơ tự check-in, theo giờ & qua đêm | 365Home';

    private const OLD_DESCRIPTION = 'Homestay tự check-in hiện đại, nơi bạn chủ động đặt và nhận phòng hoàn toàn không cần lễ tân tại Cần Thơ';
    private const NEW_DESCRIPTION = '365 Home – homestay Cần Thơ tự check-in bằng khoá thông minh, không cần lễ tân. Giá rẻ, đặt theo giờ, qua đêm hoặc theo ngày. Xem lịch trống và đặt phòng ngay!';

    public function up(): void
    {
        DB::table('pages')->where('seo_title', self::OLD_TITLE)->update(['seo_title' => self::NEW_TITLE]);
        DB::table('pages')->where('seo_description', self::OLD_DESCRIPTION)->update(['seo_description' => self::NEW_DESCRIPTION]);
    }

    public function down(): void
    {
        DB::table('pages')->where('seo_title', self::NEW_TITLE)->update(['seo_title' => self::OLD_TITLE]);
        DB::table('pages')->where('seo_description', self::NEW_DESCRIPTION)->update(['seo_description' => self::OLD_DESCRIPTION]);
    }
};

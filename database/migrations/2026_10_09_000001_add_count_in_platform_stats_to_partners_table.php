<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bật/tắt việc tính đơn + phòng của 1 đối tác vào số liệu TỔNG HỢP TOÀN HỆ THỐNG mà Super Admin xem
// (dashboard, báo cáo, API doanh thu). Tắt cho đối tác dùng thử/dữ liệu test để không làm sai doanh thu
// chung — chính đối tác đó vẫn thấy đầy đủ số liệu của mình. Xem App\Support\PlatformStats.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            if (! Schema::hasColumn('partners', 'count_in_platform_stats')) {
                $table->boolean('count_in_platform_stats')->default(true)->after('is_platform_partner');
            }
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            if (Schema::hasColumn('partners', 'count_in_platform_stats')) {
                $table->dropColumn('count_in_platform_stats');
            }
        });
    }
};

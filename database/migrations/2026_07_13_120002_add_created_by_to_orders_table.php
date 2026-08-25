<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ghi nhận ai TẠO đơn — cần để nhân viên đối tác NỀN TẢNG (vd 365home, đặt phòng hộ mọi đối
// tác khác) vẫn xem lại được đúng những đơn CHÍNH HỌ đã tạo, dù đơn đó thuộc partner_id của đối
// tác khác (xem OrderResource::getEloquentQuery()).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Phải khớp charset/collation với users.id (utf8mb4_unicode_ci) — mặc định của bảng
            // orders khác collation nên tạo FK trực tiếp sẽ báo lỗi 3780 "incompatible".
            $table->uuid('created_by')->nullable()->after('partner_id')->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};

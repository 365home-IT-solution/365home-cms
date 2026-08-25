<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'partner_id')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            // Bảng orders tạo từ trước với charset/collation cũ (utf8mb3) khác với partners
            // (utf8mb4_unicode_ci) — cột FK phải cùng charset+collation với cột partners.id
            // mới add được ràng buộc khoá ngoại (MySQL error 3780 nếu không khớp).
            $table->uuid('partner_id')->nullable()->after('id')->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'partner_id')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
            $table->dropColumn('partner_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'partner_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // Chủ đối tác: partner_id = id đối tác của chính họ.
            // Nhân viên: partner_id = id đối tác chủ quản (copy từ chủ đối tác đã tạo ra họ).
            // Super_admin: partner_id = null (không thuộc đối tác nào, xem được toàn bộ).
            $table->uuid('partner_id')->nullable()->after('created_by');
            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'partner_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
            $table->dropColumn('partner_id');
        });
    }
};

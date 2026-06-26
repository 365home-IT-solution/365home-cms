<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_lock_passwords', function (Blueprint $table) {
            $table->timestamp('valid_from')->nullable()->after('notes')->comment('Ngày bắt đầu hiệu lực');
            $table->timestamp('valid_until')->nullable()->after('valid_from')->comment('Ngày hết hạn');
            $table->boolean('is_active')->default(true)->after('valid_until')->comment('Trạng thái hoạt động');
        });
    }

    public function down(): void
    {
        Schema::table('manual_lock_passwords', function (Blueprint $table) {
            $table->dropColumn(['valid_from', 'valid_until', 'is_active']);
        });
    }
};

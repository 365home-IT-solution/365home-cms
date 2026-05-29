<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('has_manual_lock')
                ->default(false)
                ->after('lock_id_checkout')
                ->comment('Phòng dùng mật khẩu thủ công thay vì khóa TTLock');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('has_manual_lock');
        });
    }
};

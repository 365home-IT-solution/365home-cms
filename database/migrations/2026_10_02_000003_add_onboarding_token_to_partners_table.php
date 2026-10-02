<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hồ sơ đăng ký hợp tác công khai: đối tác chưa có tài khoản, truy cập hồ sơ của mình bằng mã hồ sơ (chỉ lưu sha256).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('partners', 'onboarding_token')) {
            Schema::table('partners', function (Blueprint $table) {
                $table->string('onboarding_token', 64)->nullable()->unique()->after('verification_note');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('partners', 'onboarding_token')) {
            Schema::table('partners', function (Blueprint $table) {
                $table->dropUnique(['onboarding_token']);
                $table->dropColumn('onboarding_token');
            });
        }
    }
};

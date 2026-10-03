<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MiniHouse đăng ký lần đầu: chỉ GHI NHẬN gói/số kỳ khách chọn, chưa tạo đơn thanh toán/QR — Super Admin duyệt xong mới tặng dùng thử.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            if (! Schema::hasColumn('partners', 'signup_plan_id')) {
                $table->unsignedBigInteger('signup_plan_id')->nullable();
            }
            if (! Schema::hasColumn('partners', 'signup_periods')) {
                $table->unsignedTinyInteger('signup_periods')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            foreach (['signup_plan_id', 'signup_periods'] as $column) {
                if (Schema::hasColumn('partners', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

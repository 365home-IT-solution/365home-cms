<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hợp đồng mẫu CT / VT+ĐL (Điều 6.1): khoản 365home hoàn cho khách do lỗi của Bên B được trừ vào phần doanh thu của Bên B trong kỳ đối soát gần nhất.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_settlements', function (Blueprint $table) {
            $table->integer('platform_refunded_total')->default(0)->after('platform_collected_total');
        });
    }

    public function down(): void
    {
        Schema::table('partner_settlements', function (Blueprint $table) {
            $table->dropColumn('platform_refunded_total');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cờ ẨN đơn khỏi trang khách "Tra cứu đơn đặt phòng" (/ticket-booking) mà KHÔNG xoá đơn: đơn vẫn nguyên trong hệ thống,
// admin vẫn thấy, doanh thu/đối soát không đổi — chỉ không hiện khi khách tra cứu bằng số điện thoại.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'hidden_from_lookup')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('hidden_from_lookup')->default(false)->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'hidden_from_lookup')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['hidden_from_lookup']);
            $table->dropColumn('hidden_from_lookup');
        });
    }
};

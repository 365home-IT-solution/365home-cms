<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hợp đồng mẫu Điều 6.1: hoàn tiền do lỗi cơ sở của Bên B thì trừ vào doanh thu của Bên B; do lỗi vận hành của Bên A thì Bên A tự chịu, không khấu trừ.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('refund_fault', 10)->nullable()->after('refund_paid_by');   // partner | platform | null (chưa phân loại = tính như lỗi Bên B)
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('refund_fault');
        });
    }
};

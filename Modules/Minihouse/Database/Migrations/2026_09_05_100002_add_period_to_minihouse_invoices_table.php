<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kỳ tính tiền phòng thực tế trong tháng — khách vào ở/trả phòng giữa tháng thì tiền phòng
        // phải tính theo số ngày thực ở (prorate), không phải trọn tháng. Mặc định = trọn tháng
        // (period_start/period_end null → InvoiceForm tự điền đủ tháng của cột `month`).
        Schema::table('minihouse_invoices', function (Blueprint $table) {
            $table->date('period_start')->nullable()->after('month');
            $table->date('period_end')->nullable()->after('period_start');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_invoices', function (Blueprint $table) {
            $table->dropColumn(['period_start', 'period_end']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // HOÁ ĐƠN GTGT cho phần hoa hồng của kỳ đối soát: 365home xuất hoá đơn cho đối tác (ký quỹ không phải doanh thu nên không xuất). Hệ thống lập bảng kê để kế toán
    // xuất hoá đơn và ghi nhận số hoá đơn đã xuất — xem App\Services\SettlementService::invoiceData().
    public function up(): void
    {
        if (! Schema::hasColumn('partner_settlements', 'invoice_no')) {
            Schema::table('partner_settlements', function (Blueprint $table) {
                $table->string('invoice_no', 50)->nullable();
                $table->timestamp('invoiced_at')->nullable();
                $table->uuid('invoiced_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('partner_settlements', 'invoice_no')) {
            Schema::table('partner_settlements', fn (Blueprint $table) => $table->dropColumn(['invoice_no', 'invoiced_at', 'invoiced_by']));
        }
    }
};

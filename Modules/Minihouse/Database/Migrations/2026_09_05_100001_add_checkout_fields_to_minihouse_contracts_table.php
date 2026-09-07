<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Thanh lý hợp đồng / hoàn cọc khi khách trả phòng — trước đây chỉ có deposit_amount (tiền
        // cọc lúc NHẬN phòng) và handover_file (biên bản bàn giao lúc nhận), không có chỗ ghi nhận
        // việc trả phòng thực tế.
        Schema::table('minihouse_contracts', function (Blueprint $table) {
            $table->date('checkout_at')->nullable()->after('end_date');
            $table->decimal('deposit_refunded_amount', 14, 2)->nullable()->after('deposit_amount');
            $table->text('deposit_deduction_reason')->nullable()->after('deposit_refunded_amount');
            $table->string('checkout_handover_file')->nullable()->after('deposit_receipt_file');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_contracts', function (Blueprint $table) {
            $table->dropColumn(['checkout_at', 'deposit_refunded_amount', 'deposit_deduction_reason', 'checkout_handover_file']);
        });
    }
};

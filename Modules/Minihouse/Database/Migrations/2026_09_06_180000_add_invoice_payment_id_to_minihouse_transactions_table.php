<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Liên kết 1-1 tới InvoicePayment sinh ra dòng "Thu" này — để InvoicePaymentObserver biết đúng
// dòng Transaction nào cần cập nhật/xoá khi sửa/xoá 1 lần thanh toán, không tạo trùng lặp mỗi lần
// đồng bộ lại. NULL với các giao dịch nhập tay bình thường (sửa chữa, vận hành...).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_transactions', function (Blueprint $table) {
            $table->foreignId('invoice_payment_id')->nullable()->unique()->after('building_id')
                ->constrained('minihouse_invoice_payments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_payment_id');
        });
    }
};

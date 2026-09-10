<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('minihouse_invoice_payments', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('note');
            $table->timestamp('approved_at')->nullable()->after('status');
            // users.id là UUID (không phải bigint) — dùng uuid() + foreign() tay giống hệt cột
            // created_by ở migration tạo bảng gốc, KHÔNG dùng foreignId() (mặc định unsignedBigInteger,
            // sai kiểu, gây lỗi "incompatible" khi tạo khoá ngoại).
            $table->uuid('approved_by')->nullable()->after('approved_at');
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });

        // Dữ liệu CŨ (trước khi có bước duyệt) coi như đã duyệt sẵn — không hồi tố bắt duyệt lại,
        // tránh làm "sống lại" trạng thái "chưa thanh toán" cho hàng loạt hoá đơn đã xong từ trước.
        // Chỉ payment tạo MỚI sau migration này mới mặc định 'pending' (do code ứng dụng set khi
        // tạo, xem InvoiceForm/InvoicePaymentController — không dựa vào default cột DB vì webhook
        // PayOS ghi 'approved' thẳng ngay lúc tạo).
        DB::table('minihouse_invoice_payments')->update(['status' => 'approved']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('minihouse_invoice_payments', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['status', 'approved_at', 'approved_by']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Từng LẦN thanh toán của 1 hoá đơn — tách bảng riêng (không chỉ 1 cột amount_paid trên Invoice) để
// hỗ trợ khách trả nhiều lần (trả 1 phần trước, phần còn lại sau), và giữ lại lịch sử ai ghi nhận,
// ngày nào, hình thức gì — cần khi đối chiếu sau này. Invoice.amount_paid/paid_at/status vẫn là cột
// CACHE (giống hệt cách Invoice.service_amount cache từ InvoiceItem, Invoice.total_amount cache từ
// các thành phần) — tự đồng bộ lại mỗi khi 1 dòng payment thay đổi, xem InvoicePaymentObserver.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('minihouse_invoices')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_at');
            $table->string('payment_method')->nullable();
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('minihouse_invoices', function (Blueprint $table) {
            $table->decimal('amount_paid', 12, 2)->default(0)->after('total_amount');
            $table->timestamp('paid_at')->nullable()->after('amount_paid');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_invoices', function (Blueprint $table) {
            $table->dropColumn(['amount_paid', 'paid_at']);
        });

        Schema::dropIfExists('minihouse_invoice_payments');
    }
};

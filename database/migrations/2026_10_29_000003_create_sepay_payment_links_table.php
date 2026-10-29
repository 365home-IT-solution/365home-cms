<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yêu cầu thanh toán qua SePay của đơn (thay cho "link PayOS"): mỗi lần hệ thống cần thu tiền (cọc / đủ / phần còn lại /
 * phát sinh / cọc lại) tạo 1 dòng với đúng số tiền cần thu. Mã `code` chính là mã khách ghi trong nội dung chuyển khoản
 * (DH{code}) — webhook SePay tra theo mã này, đối chiếu số tiền, rồi xử lý như webhook PayOS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sepay_payment_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('code')->unique();
            $table->unsignedBigInteger('amount');
            $table->string('status', 20)->default('pending'); // pending | paid | cancelled
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('sepay_transaction_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sepay_payment_links');
    }
};

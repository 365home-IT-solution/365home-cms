<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Khoản thu tiền ĐỘC LẬP do admin tạo (không gắn đơn/gói/ký quỹ nào): số tiền + nội dung → mã TTxxxxxx, QR chuyển khoản vào tài khoản Pay2S của 365home.
 * Tài khoản nhận được CHỤP LẠI lúc tạo (bank_bin/account_number/account_holder) để QR đã gửi khách không đổi dù sau đó đổi cấu hình.
 * status: pending | paid | cancelled. Tiền về muộn (quá expires_at) vẫn ghi nhận nếu chưa huỷ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_collections')) {
            return;
        }

        Schema::create('payment_collections', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->unsignedBigInteger('amount');
            $table->string('description', 255)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->string('bank_code', 20)->nullable();
            $table->string('bank_bin', 12);
            $table->string('account_number', 50);
            $table->string('account_holder')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('paid_amount')->nullable();
            $table->string('bank_reference')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_collections');
    }
};

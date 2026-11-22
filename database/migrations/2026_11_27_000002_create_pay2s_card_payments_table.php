<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phiên thanh toán thẻ Pay2S của đơn: mỗi lần khách bấm "Thanh toán thẻ" tạo 1 dòng đúng số tiền cần thu (đủ / cọc / phần còn lại / phát sinh).
 * pay2s_order_id là mã đơn gửi Pay2S (duy nhất); webhook tra theo mã này, đối chiếu số tiền + môi trường rồi mới xác nhận đơn.
 * status: pending | paid | cancelled | failed | expired | review (Pay2S báo đã thu nhưng đơn không còn nhận được khoản này → cần hoàn tiền thủ công).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay2s_card_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('kind', 20);                        // initial | remaining | extra
            $table->string('pay2s_order_id', 128)->unique();
            $table->unsignedBigInteger('amount');
            $table->string('card_brand', 20)->nullable();
            $table->string('session_id', 100)->nullable();
            $table->string('environment', 20)->nullable();     // sandbox | production
            $table->text('payment_url')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('failure_reason', 500)->nullable();
            $table->string('trans_id', 100)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('payload')->nullable();               // webhook/status gốc để đối soát
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay2s_card_payments');
    }
};

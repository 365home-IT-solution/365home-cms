<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Mọi giao dịch tiền vào do SePay báo về (kể cả không khớp đơn nào) — để chống xử lý trùng khi SePay gửi lại webhook
    // (unique provider + provider_txn_id) và để đối soát/gán tay những khoản khách ghi sai nội dung hay chuyển thiếu.
    public function up(): void
    {
        if (Schema::hasTable('bank_transactions')) {
            return;
        }

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('sepay');
            $table->string('provider_txn_id', 64);
            $table->uuid('partner_id')->nullable()->index();
            $table->string('gateway', 50)->nullable();
            $table->string('account_number', 50)->nullable();
            $table->unsignedBigInteger('amount');
            $table->text('content')->nullable();
            $table->string('reference_code', 100)->nullable();
            $table->timestamp('transacted_at')->nullable();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            // matched | unmatched | amount_mismatch | needs_review
            $table->string('status', 20)->index();
            // Phần nào của đơn được ghi nhận: payment (cọc/thanh toán đủ) | remaining | extra_charge
            $table->string('applied_as', 20)->nullable();
            $table->string('note')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_txn_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};

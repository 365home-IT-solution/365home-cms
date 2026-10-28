<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Kênh CHUYỂN KHOẢN NGÂN HÀNG (VietQR + SePay) của đối tác Homestay — thay cho kênh PayOS riêng khi đối tác không đăng ký
    // được PayOS (PayOS bắt chính chủ kinh doanh). Khách quét VietQR chuyển thẳng vào tài khoản đối tác, SePay theo dõi biến động
    // số dư và gọi webhook về 365home để tự xác nhận đơn — xem App\Services\Payment\SepayWebhookService.
    // webhook_key: khoá API dán vào mục "Bảo mật" của webhook trên SePay (mã hoá ở model); webhook_key_hash để tra kênh theo khoá.
    public function up(): void
    {
        if (Schema::hasTable('partner_bank_channels')) {
            return;
        }

        Schema::create('partner_bank_channels', function (Blueprint $table) {
            $table->id();
            $table->uuid('partner_id')->unique();
            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('bank_code', 20);
            $table->string('account_number', 50)->index();
            $table->string('account_holder')->nullable();
            $table->text('webhook_key');
            $table->char('webhook_key_hash', 64)->unique();
            $table->timestamp('last_webhook_at')->nullable();
            $table->string('note')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_bank_channels');
    }
};

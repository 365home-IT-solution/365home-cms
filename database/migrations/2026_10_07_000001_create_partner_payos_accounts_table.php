<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Kênh PayOS RIÊNG của từng ĐỐI TÁC Homestay — khách đặt phòng ở mọi chi nhánh của đối tác trả thẳng
    // vào tài khoản đối tác. Đây là nơi cấu hình CHÍNH; branch_payos_accounts (theo chi nhánh) chỉ còn
    // là ghi đè cho chi nhánh có pháp nhân/tài khoản khác — xem App\Services\Payment\PayOsAccountResolver.
    // 3 khoá lưu dạng text vì được mã hoá (cast 'encrypted' ở model).
    public function up(): void
    {
        if (Schema::hasTable('partner_payos_accounts')) {
            return;
        }

        Schema::create('partner_payos_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('partner_id')->unique();
            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('client_id');
            $table->text('api_key');
            $table->text('checksum_key');
            $table->string('account_holder')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('webhook_confirmed_at')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_payos_accounts');
    }
};

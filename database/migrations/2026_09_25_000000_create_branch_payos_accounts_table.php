<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Tài khoản PayOS RIÊNG của từng chi nhánh Homestay (categories gốc, category_type=product) —
    // chủ nhà hợp tác được hỗ trợ kết nối PayOS của chính họ, khách đặt phòng ở chi nhánh đó trả
    // thẳng vào tài khoản chủ nhà thay vì tài khoản chung (payment_configurations). Chi nhánh không
    // có dòng ở đây (hoặc is_active=false) vẫn dùng tài khoản chung như cũ — xem
    // App\Services\Payment\PayOsAccountResolver. Bảng RIÊNG, không dùng chung
    // minihouse_building_settings (của Toà nhà MiniHouse, cùng bảng categories nhưng khác nghiệp vụ).
    // 3 khoá lưu dạng text vì được mã hoá (cast 'encrypted' ở model) — chuỗi mã hoá dài hơn 255.
    public function up(): void
    {
        if (Schema::hasTable('branch_payos_accounts')) {
            return;
        }

        Schema::create('branch_payos_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->unique()->constrained('categories')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('client_id');
            $table->text('api_key');
            $table->text('checksum_key');
            $table->string('account_holder')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('webhook_confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_payos_accounts');
    }
};

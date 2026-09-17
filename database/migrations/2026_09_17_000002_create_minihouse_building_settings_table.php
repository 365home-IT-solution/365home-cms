<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Giai đoạn 1 của việc gộp Toà nhà MiniHouse vào categories: bảng phụ 1-1 giữ những cột MiniHouse
// cần mà Category (dùng làm "chi nhánh" cho Home) không có — ngân hàng/QR chủ nhà, cổng thanh toán
// riêng của từng toà (PayOS/MoMo/VNPay), chu kỳ tính tiền/nhắc nhở. `address` cũng nằm ở đây vì
// bảng categories hiện KHÔNG có cột address (đã kiểm tra: Modules/Category/Entities/Category.php
// $fillable không có 'address'). province/ward chuyển sang dùng bảng province_branches có sẵn của
// Home ở Giai đoạn 2 — 2 cột *_raw ở đây chỉ là lưới an toàn giữ lại giá trị gốc nếu không khớp
// được với Province nào trong hệ Home, tránh mất dữ liệu.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('minihouse_building_settings')) {
            return;
        }

        Schema::create('minihouse_building_settings', function (Blueprint $table) {
            $table->foreignId('category_id')->primary()->constrained('categories')->cascadeOnDelete();

            $table->foreignId('zone_id')->nullable()->constrained('minihouse_zones')->nullOnDelete();
            $table->string('address')->nullable();
            $table->string('province_name_raw')->nullable();
            $table->string('ward_raw')->nullable();

            $table->decimal('electric_unit_price', 12, 2)->nullable();
            $table->decimal('water_unit_price', 12, 2)->nullable();

            $table->string('owner_name')->nullable();
            $table->string('owner_phone')->nullable();
            $table->string('owner_id_card_number')->nullable();
            $table->string('owner_email')->nullable();
            $table->string('owner_address')->nullable();
            $table->string('owner_bank_bin')->nullable();
            $table->string('owner_bank_name')->nullable();
            $table->string('owner_bank_account_number')->nullable();
            $table->string('owner_bank_account_holder')->nullable();

            $table->string('payment_method')->nullable();
            $table->string('payos_client_id')->nullable();
            $table->string('payos_api_key')->nullable();
            $table->string('payos_checksum_key')->nullable();
            $table->string('momo_partner_code')->nullable();
            $table->string('momo_access_key')->nullable();
            $table->string('momo_secret_key')->nullable();
            $table->string('vnpay_tmn_code')->nullable();
            $table->string('vnpay_hash_secret')->nullable();
            $table->boolean('payment_sandbox')->default(false);

            $table->string('billing_cycle_type')->default('calendar_month');
            $table->unsignedTinyInteger('payment_reminder_days_before')->nullable();
            $table->unsignedTinyInteger('payment_reminder_repeat_days')->nullable();
            $table->unsignedTinyInteger('fixed_due_day')->nullable();
            $table->unsignedTinyInteger('contract_expiry_reminder_days_before')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_building_settings');
    }
};

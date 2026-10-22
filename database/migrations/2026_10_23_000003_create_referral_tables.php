<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // MINI GAME GIỚI THIỆU BẠN BÈ: mỗi khách có 1 mã cố định; bạn mới nhập mã ⇒ nhận voucher chào mừng; bạn ở xong đơn đầu ⇒ người giới thiệu nhận
    // 1 thẻ cào (source = referral). Xem App\Services\ReferralService.
    public function up(): void
    {
        if (! Schema::hasTable('referral_settings')) {
            Schema::create('referral_settings', function (Blueprint $table) {
                $table->id();
                $table->boolean('is_enabled')->default(false);
                // Voucher chào mừng cho bạn mới (dùng chung, 365home chịu)
                $table->string('coupon_type', 12)->default('fixed'); // fixed | percentage
                $table->decimal('coupon_value', 12, 2)->default(50000);
                $table->decimal('min_order_value', 12, 2)->nullable();
                $table->decimal('max_discount', 12, 2)->nullable();
                $table->unsignedSmallInteger('validity_days')->default(30);
                $table->unsignedSmallInteger('monthly_limit')->default(10); // số phần thưởng tối đa / người giới thiệu / tháng
                $table->unsignedSmallInteger('apply_days')->default(7); // số ngày kể từ lúc đăng ký còn được nhập mã
                $table->string('share_url_base')->default('https://365home.vn/r/');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('referral_codes')) {
            Schema::create('referral_codes', function (Blueprint $table) {
                $table->id();
                $table->char('customer_id', 36)->unique();
                $table->string('code', 12)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('referrals')) {
            Schema::create('referrals', function (Blueprint $table) {
                $table->id();
                $table->char('referrer_id', 36)->index();
                $table->char('referee_id', 36)->unique(); // một tài khoản chỉ nhập mã một lần
                $table->string('code', 12);
                $table->string('device_id', 100)->nullable()->index();
                $table->string('status', 10)->default('joined')->index(); // joined | rewarded | invalid (booked suy ra khi hiển thị)
                $table->string('reason', 40)->nullable();
                $table->unsignedBigInteger('welcome_coupon_id')->nullable();
                $table->unsignedBigInteger('first_order_id')->nullable()->index();
                $table->char('scratch_card_id', 26)->nullable();
                $table->timestamp('rewarded_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('referral_codes');
        Schema::dropIfExists('referral_settings');
    }
};

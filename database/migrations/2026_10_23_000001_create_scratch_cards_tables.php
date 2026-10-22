<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // MINI GAME THẺ CÀO: đơn đã trả phòng → 1 thẻ cào, BE bốc giải theo trọng số khi khách cào (xem App\Services\ScratchCardService).
    // coupons.source ghi nguồn voucher (membership mặc định / scratch_card / collection / referral) để app dán nhãn ở ví voucher.
    public function up(): void
    {
        if (! Schema::hasColumn('coupons', 'source')) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->string('source', 20)->nullable()->after('template_coupon_id');
            });
        }

        if (! Schema::hasTable('scratch_card_settings')) {
            Schema::create('scratch_card_settings', function (Blueprint $table) {
                $table->id();
                $table->boolean('is_enabled')->default(false);
                $table->unsignedSmallInteger('expire_days')->default(14);
                // Mặc định trả phòng là được thẻ ngay (0). Đặt > 0 nếu muốn đơn phải ở tối thiểu bấy nhiêu ngày (ngày trả − ngày nhận) và/hoặc đạt mức tiền tối thiểu.
                $table->unsignedSmallInteger('min_stay_days')->default(0);
                $table->unsignedBigInteger('min_order_amount')->default(0);
                $table->boolean('rating_bonus_enabled')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('scratch_card_prizes')) {
            Schema::create('scratch_card_prizes', function (Blueprint $table) {
                $table->id();
                $table->string('label');
                $table->string('type', 10)->default('coupon'); // coupon | none
                $table->string('tier', 10)->default('normal'); // normal | rare | jackpot
                $table->string('coupon_type', 12)->nullable(); // fixed | percentage
                $table->decimal('coupon_value', 12, 2)->nullable();
                $table->decimal('min_order_value', 12, 2)->nullable();
                $table->decimal('max_discount', 12, 2)->nullable();
                $table->unsignedSmallInteger('validity_days')->default(30);
                $table->unsignedInteger('weight')->default(1);
                $table->unsignedInteger('daily_limit')->nullable();
                $table->unsignedInteger('total_limit')->nullable();
                $table->unsignedInteger('awarded_count')->default(0);
                $table->boolean('is_fallback')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('scratch_cards')) {
            Schema::create('scratch_cards', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->char('customer_id', 36)->index();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->string('source', 20)->default('order'); // order | rating_bonus | referral
                $table->string('status', 10)->default('available')->index(); // available | scratched | expired | voided
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('scratched_at')->nullable();
                $table->unsignedBigInteger('prize_id')->nullable();
                $table->string('prize_label')->nullable();
                $table->string('prize_type', 10)->nullable();
                $table->string('prize_tier', 10)->nullable();
                $table->unsignedBigInteger('coupon_id')->nullable();
                $table->timestamps();

                // Mỗi đơn chỉ có 1 thẻ mỗi nguồn — chống sinh trùng khi hook đổi trạng thái và job quét bù cùng chạy.
                $table->unique(['order_id', 'source']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scratch_cards');
        Schema::dropIfExists('scratch_card_prizes');
        Schema::dropIfExists('scratch_card_settings');
        if (Schema::hasColumn('coupons', 'source')) {
            Schema::table('coupons', fn (Blueprint $table) => $table->dropColumn('source'));
        }
    }
};

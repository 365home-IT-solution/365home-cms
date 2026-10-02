<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// GÓI DỊCH VỤ đối tác — CHỈ MINIHOUSE: 1 gói duy nhất 199.000đ/tháng (mở toàn bộ chức năng), mua theo kỳ 1/3/6/9/12 tháng (config subscription.period_options);
// đăng ký gói của từng đối tác MiniHouse và các lần thanh toán phí. Homestay không dùng gói.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // Loại đối tác áp dụng (chỉ 'minihouse').
            $table->string('partner_type', 20)->nullable();
            // Giá mỗi THÁNG (đồng) và số tháng của 1 kỳ. Giá = 0 nghĩa là chưa cấu hình → chưa thanh toán online được.
            $table->unsignedBigInteger('price_vnd')->default(0);
            $table->unsignedSmallInteger('period_months')->default(1);
            // Số tháng dùng thử khi đối tác MỚI đăng ký vào gói này (chỉ áp dụng nếu is_default).
            $table->unsignedSmallInteger('trial_months')->default(6);
            // Các dòng thông tin hỗ trợ hiển thị trên thẻ gói.
            $table->json('support_info')->nullable();
            // Gói tự gán cho đối tác mới (mỗi loại đối tác 1 gói mặc định).
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('partner_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('partner_id');
            $table->foreignId('plan_id')->constrained('subscription_plans');
            // trial | active | cancelled (hết hạn tính theo expires_at, không lưu).
            $table->string('status', 20)->default('trial');
            $table->boolean('is_trial')->default(false);
            $table->timestamp('started_at')->nullable();
            // null = không giới hạn thời gian.
            $table->timestamp('expires_at')->nullable();
            $table->boolean('auto_renew')->default(false);
            // Mốc nhắc đã gửi cho kỳ hạn hiện tại (tránh gửi lặp): [30, 7, ...]
            $table->json('reminders_sent')->nullable();
            $table->timestamp('renewal_link_sent_at')->nullable();
            $table->timestamp('expired_notified_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique('partner_id', 'psub_partner_unique');
            $table->index('expires_at', 'psub_expires_idx');
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('partner_id');
            $table->foreignId('plan_id')->constrained('subscription_plans');
            // Mã giao dịch hiển thị cho đối tác & công ty đối soát (cũng là nội dung chuyển khoản).
            $table->string('transaction_code', 20)->unique();
            $table->unsignedBigInteger('amount_vnd');
            $table->unsignedSmallInteger('months');
            // pending | paid | expired | cancelled
            $table->string('status', 20)->default('pending');
            // manual: Super Admin ghi nhận; auto_renew: link tạo tự động trước hạn.
            $table->string('source', 20)->default('payos');
            $table->boolean('is_renewal')->default(false);
            $table->unsignedBigInteger('payos_order_code')->nullable()->unique('spay_order_unique');
            $table->string('payos_payment_link_id')->nullable();
            $table->text('payos_checkout_url')->nullable();
            $table->text('payos_qr_code')->nullable();
            $table->string('payos_bank_bin', 20)->nullable();
            $table->string('payos_account_number', 50)->nullable();
            $table->string('payos_account_name')->nullable();
            $table->timestamp('payos_expired_at')->nullable();
            $table->string('bank_reference')->nullable()->unique('spay_ref_unique');
            $table->timestamp('paid_at')->nullable();
            // Hạn dùng của đối tác SAU khi thanh toán này được áp dụng.
            $table->timestamp('extends_to')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'status'], 'spay_partner_status_idx');
        });

        // Gói MiniHouse duy nhất (đối tác MiniHouse mới tự nhận gói này, dùng thử 6 tháng).
        $now = now();
        DB::table('subscription_plans')->insert([
            'code' => 'mh-monthly', 'name' => 'MiniHouse',
            'description' => 'Gói duy nhất cho MiniHouse: toàn bộ chức năng quản trị, không giới hạn toà nhà và phòng. Mua theo 1, 3, 6, 9 hoặc 12 tháng.',
            'partner_type' => 'minihouse', 'price_vnd' => 199000, 'period_months' => 1, 'trial_months' => 6,
            'support_info' => json_encode(['Hỗ trợ kỹ thuật: Zalo & hotline, giờ hành chính']),
            'is_default' => true, 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('partner_subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};

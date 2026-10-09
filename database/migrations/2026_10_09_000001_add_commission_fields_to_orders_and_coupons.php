<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // HOA HỒNG + AI CHỊU KHUYẾN MÃI — xem App\Services\OrderCommissionService.
    //  - orders.collected_by: tiền đặt phòng về đâu (partner | platform | cash), chụp lúc tạo đơn.
    //  - orders.commission_rate: tỉ lệ hoa hồng chụp lúc đặt — đổi hợp đồng sau không làm sai đơn cũ.
    //  - orders.commission_amount / platform_subsidy / discounts: chốt khi đơn hoàn thành (xem commission_finalized_at).
    //  - orders.settlement_id: thuộc kỳ đối soát nào (null = chưa vào kỳ).
    //  - orders.subsidy_held_*: Super Admin giữ khoản 365home bù của đơn nghi giả để xem xét.
    //  - coupons.funded_by / partner_share_pct: ai chịu tiền giảm của mã (partner | platform | shared).
    //  - coupon_partner_participations: đối tác đồng ý (bật/tắt theo chi nhánh) tham gia chiến dịch đồng tài trợ.
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'collected_by')) {
                $table->string('collected_by', 10)->nullable()->index();
                $table->decimal('commission_rate', 5, 2)->nullable();
                $table->bigInteger('commission_amount')->nullable();
                $table->unsignedBigInteger('platform_subsidy')->default(0);
                $table->json('discounts')->nullable();
                $table->unsignedBigInteger('settlement_id')->nullable()->index();
                $table->timestamp('commission_finalized_at')->nullable()->index();
                $table->timestamp('subsidy_held_at')->nullable();
                $table->string('subsidy_held_reason')->nullable();
                // Ai đã hoàn tiền cho khách: partner (đối tác tự hoàn) | platform (365home hoàn thay → trừ ký quỹ).
                $table->string('refund_paid_by', 10)->nullable();
            }
        });

        Schema::table('coupons', function (Blueprint $table) {
            if (! Schema::hasColumn('coupons', 'funded_by')) {
                $table->string('funded_by', 10)->default('partner');
                $table->unsignedTinyInteger('partner_share_pct')->nullable();
            }
        });

        // Mã đã có: không thuộc đối tác nào (Super Admin tạo), mã cấp theo hạng thành viên hoặc thưởng đăng nhập
        // đều là tiền của 365home — giữ đúng ý nghĩa cũ thay vì đẩy khuyến mãi của 365home sang đối tác.
        DB::table('coupons')->where(function ($q) {
            $q->whereNull('partner_id')->orWhereNotNull('template_coupon_id')->orWhereNotNull('auto_issue_tier_id');
        })->update(['funded_by' => 'platform']);

        if (! Schema::hasTable('coupon_partner_participations')) {
            Schema::create('coupon_partner_participations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('coupon_id')->index();
                $table->uuid('partner_id')->index();
                $table->unsignedBigInteger('category_id')->index();
                $table->boolean('is_enabled')->default(true);
                $table->uuid('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();
                $table->unique(['coupon_id', 'category_id'], 'coupon_participation_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_partner_participations');

        Schema::table('coupons', function (Blueprint $table) {
            if (Schema::hasColumn('coupons', 'funded_by')) {
                $table->dropColumn(['funded_by', 'partner_share_pct']);
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'collected_by')) {
                $table->dropColumn(['collected_by', 'commission_rate', 'commission_amount', 'platform_subsidy', 'discounts', 'settlement_id', 'commission_finalized_at', 'subsidy_held_at', 'subsidy_held_reason', 'refund_paid_by']);
            }
        });
    }
};

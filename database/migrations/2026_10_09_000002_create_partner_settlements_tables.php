<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // KỲ ĐỐI SOÁT hoa hồng đối tác Homestay — xem App\Services\SettlementService.
    //  - partner_settlements: 1 bảng đối soát / đối tác / kỳ (theo partners.payment_cycle).
    //    net_amount có dấu: dương = đối tác phải nộp 365home, âm = 365home phải chi cho đối tác.
    //  - partner_settlement_disputes: khiếu nại từng đơn trong bảng đối soát.
    public function up(): void
    {
        if (! Schema::hasTable('partner_settlements')) {
            Schema::create('partner_settlements', function (Blueprint $table) {
                $table->id();
                $table->string('code', 30)->unique();
                $table->uuid('partner_id')->index();
                $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
                $table->date('period_start');
                $table->date('period_end');
                $table->string('cycle', 20);
                // draft → sent → disputed → paid | deducted (trừ ký quỹ khi quá hạn) | paid_out (365home đã chi)
                $table->string('status', 20)->default('draft')->index();
                $table->unsignedInteger('orders_count')->default(0);
                $table->unsignedBigInteger('revenue_total')->default(0);            // Σ tiền khách trả của đơn hoàn thành
                $table->unsignedBigInteger('commission_total')->default(0);         // Σ hoa hồng
                $table->unsignedBigInteger('subsidy_total')->default(0);            // Σ 365home bù (khuyến mãi 365home chịu)
                $table->unsignedBigInteger('platform_collected_total')->default(0); // Σ tiền 365home đã thu hộ (đơn collected_by = platform)
                $table->bigInteger('net_amount')->default(0);
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('due_at')->nullable();
                $table->timestamp('reminded_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('deducted_at')->nullable();
                $table->unsignedBigInteger('deducted_entry_id')->nullable();
                $table->timestamp('paid_out_at')->nullable();
                $table->string('paid_out_reference')->nullable();
                $table->uuid('paid_out_by')->nullable();
                $table->unsignedBigInteger('payos_order_code')->nullable()->unique();
                $table->string('payos_payment_link_id')->nullable();
                $table->text('payos_checkout_url')->nullable();
                $table->text('payos_qr_code')->nullable();
                $table->string('payos_bank_bin', 20)->nullable();
                $table->string('payos_account_number', 50)->nullable();
                $table->string('payos_account_name')->nullable();
                $table->timestamp('payos_expired_at')->nullable();
                $table->text('note')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->unique(['partner_id', 'period_start', 'period_end'], 'partner_settlement_period_unique');
            });
        }

        if (! Schema::hasTable('partner_settlement_disputes')) {
            Schema::create('partner_settlement_disputes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('settlement_id')->index();
                $table->foreign('settlement_id')->references('id')->on('partner_settlements')->cascadeOnDelete();
                $table->unsignedBigInteger('order_id')->index();
                $table->string('order_code', 50);
                $table->string('status', 20)->default('open')->index(); // open | accepted | rejected
                $table->text('reason');
                $table->bigInteger('adjusted_commission')->nullable();
                $table->text('resolution_note')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('resolved_by')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_settlement_disputes');
        Schema::dropIfExists('partner_settlements');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // YÊU CẦU HOÀN TIỀN KHÁCH của đơn mà tiền đang nằm ở đối tác (luồng tiền mới): đối tác có refund_grace_hours (mặc định 24 giờ) để hoàn; quá hạn mà chưa hoàn thì
    // 365home hoàn thay rồi trừ ký quỹ — xem App\Services\RefundClaimService.
    public function up(): void
    {
        if (Schema::hasTable('partner_refund_claims')) {
            return;
        }

        Schema::create('partner_refund_claims', function (Blueprint $table) {
            $table->id();
            $table->uuid('partner_id')->index();
            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('order_code', 50)->index();
            $table->unsignedBigInteger('amount');
            $table->text('reason');
            // open → overdue (quá hạn chưa hoàn) → refunded_by_partner | refunded_by_platform | cancelled
            $table->string('status', 24)->default('open')->index();
            $table->timestamp('requested_at');
            $table->timestamp('due_at')->index();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('requested_by')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_refund_claims');
    }
};

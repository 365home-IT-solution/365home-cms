<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // KÝ QUỸ ĐỐI TÁC HOMESTAY — xem App\Services\EscrowService.
    //  - partners.escrow_*: mức tối thiểu (null = đối tác CHƯA áp dụng ký quỹ, không bị khoá bán), số dư
    //    (bản sao của tổng sổ, chỉ EscrowService ghi), hạn nạp bù, thời điểm bị tạm ngưng bán.
    //  - partner_escrow_entries: SỔ bút toán — số dư chỉ đổi qua đây, không sửa/xoá, sai thì ghi bút toán đảo.
    //  - partner_escrow_deposits: yêu cầu nạp qua QR PayOS của 365home (tiền về 365home).
    //  - partner_escrow_deductions: ĐỀ XUẤT trừ — đối tác đồng ý/khiếu nại rồi mới sinh bút toán.
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            if (! Schema::hasColumn('partners', 'escrow_min_amount')) {
                $table->unsignedBigInteger('escrow_min_amount')->nullable()->after('commission_rate');
                $table->bigInteger('escrow_balance')->default(0)->after('escrow_min_amount');
                $table->timestamp('escrow_enforced_from')->nullable()->after('escrow_balance');
                $table->timestamp('escrow_topup_due_at')->nullable()->after('escrow_enforced_from');
                $table->timestamp('escrow_suspended_at')->nullable()->after('escrow_topup_due_at');
            }
        });

        if (! Schema::hasTable('partner_escrow_deductions')) {
            Schema::create('partner_escrow_deductions', function (Blueprint $table) {
                $table->id();
                $table->uuid('partner_id')->index();
                $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
                $table->string('type', 30);
                $table->unsignedBigInteger('amount');
                $table->unsignedBigInteger('final_amount')->nullable();
                $table->string('status', 20)->default('pending')->index();
                $table->boolean('is_urgent')->default(false);
                $table->text('reason');
                $table->string('order_code')->nullable();
                $table->string('reference')->nullable();
                $table->timestamp('respond_by')->nullable();
                $table->text('dispute_reason')->nullable();
                $table->timestamp('disputed_at')->nullable();
                $table->uuid('disputed_by')->nullable();
                $table->string('resolution', 20)->nullable();
                $table->text('resolution_note')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->uuid('resolved_by')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('partner_escrow_deposits')) {
            Schema::create('partner_escrow_deposits', function (Blueprint $table) {
                $table->id();
                $table->uuid('partner_id')->index();
                $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
                $table->string('transaction_code', 20)->unique();
                $table->unsignedBigInteger('amount');
                $table->string('status', 20)->default('pending')->index();
                $table->unsignedBigInteger('payos_order_code')->nullable()->unique();
                $table->string('payos_payment_link_id')->nullable();
                $table->text('payos_checkout_url')->nullable();
                $table->text('payos_qr_code')->nullable();
                $table->string('payos_bank_bin', 20)->nullable();
                $table->string('payos_account_number', 50)->nullable();
                $table->string('payos_account_name')->nullable();
                $table->timestamp('payos_expired_at')->nullable();
                $table->string('bank_reference')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('partner_escrow_entries')) {
            Schema::create('partner_escrow_entries', function (Blueprint $table) {
                $table->id();
                $table->uuid('partner_id');
                $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
                $table->string('type', 30)->index();
                // Có dấu: nạp dương, trừ/hoàn âm.
                $table->bigInteger('amount');
                $table->bigInteger('balance_after');
                $table->text('reason')->nullable();
                $table->string('order_code')->nullable();
                $table->string('reference')->nullable();
                $table->foreignId('deduction_id')->nullable()->constrained('partner_escrow_deductions')->nullOnDelete();
                $table->foreignId('deposit_id')->nullable()->unique()->constrained('partner_escrow_deposits')->nullOnDelete();
                $table->foreignId('reverses_entry_id')->nullable()->unique()->constrained('partner_escrow_entries')->nullOnDelete();
                $table->boolean('is_urgent')->default(false);
                $table->uuid('created_by')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['partner_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_escrow_entries');
        Schema::dropIfExists('partner_escrow_deposits');
        Schema::dropIfExists('partner_escrow_deductions');

        Schema::table('partners', function (Blueprint $table) {
            if (Schema::hasColumn('partners', 'escrow_min_amount')) {
                $table->dropColumn(['escrow_min_amount', 'escrow_balance', 'escrow_enforced_from', 'escrow_topup_due_at', 'escrow_suspended_at']);
            }
        });
    }
};

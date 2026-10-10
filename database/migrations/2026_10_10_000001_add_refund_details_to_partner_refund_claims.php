<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Chi tiết việc hoàn tiền của yêu cầu hoàn tiền khách: hình thức (cash/transfer), số tiền thực hoàn và ghi chú của người hoàn.
    // Người hoàn dùng lại cột resolved_by có sẵn. Để Super Admin xem được "ai hoàn, hoàn thế nào" khi yêu cầu chuyển sang đã hoàn.
    public function up(): void
    {
        if (! Schema::hasTable('partner_refund_claims') || Schema::hasColumn('partner_refund_claims', 'refund_method')) {
            return;
        }

        Schema::table('partner_refund_claims', function (Blueprint $table) {
            $table->string('refund_method', 20)->nullable()->after('resolved_by');
            $table->unsignedBigInteger('refunded_amount')->nullable()->after('refund_method');
            $table->string('refund_note', 500)->nullable()->after('refunded_amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('partner_refund_claims', 'refund_method')) {
            return;
        }

        Schema::table('partner_refund_claims', function (Blueprint $table) {
            $table->dropColumn(['refund_method', 'refunded_amount', 'refund_note']);
        });
    }
};

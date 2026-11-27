<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Khoản thẻ "review" (Pay2S đã thu tiền nhưng đơn không nhận được) cần hoàn tiền thủ công. Sau khi xử lý xong, admin đánh dấu "resolved" kèm ghi chú
 * để khoản không nằm mãi trong danh sách cần xử lý và huy hiệu đỏ ở menu tự hết.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay2s_card_payments', function (Blueprint $table) {
            $table->timestamp('resolved_at')->nullable()->after('paid_at');
            $table->string('resolved_by', 64)->nullable()->after('resolved_at');
            $table->string('resolution_note', 500)->nullable()->after('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('pay2s_card_payments', function (Blueprint $table) {
            $table->dropColumn(['resolved_at', 'resolved_by', 'resolution_note']);
        });
    }
};

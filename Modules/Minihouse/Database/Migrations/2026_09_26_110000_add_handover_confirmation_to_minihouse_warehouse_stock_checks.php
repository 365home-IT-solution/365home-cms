<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Xác nhận bàn giao ca" cho phiếu kiểm kê — mirror migration 2026_08_15_000014 của Home. Chỉ là bước
// đối chiếu (ca sau đếm lại), KHÔNG điều chỉnh tồn kho.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_warehouse_stock_checks', function (Blueprint $table) {
            $table->string('handover_status')->nullable();
            $table->uuid('handover_confirmed_by')->nullable();
            $table->foreign('handover_confirmed_by', 'mhw_chk_hand_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('handover_confirmed_at')->nullable();
            $table->text('handover_note')->nullable();
        });

        Schema::table('minihouse_warehouse_stock_check_items', function (Blueprint $table) {
            $table->decimal('handover_quantity', 15, 2)->nullable();
            $table->decimal('handover_difference', 15, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_warehouse_stock_check_items', function (Blueprint $table) {
            $table->dropColumn(['handover_quantity', 'handover_difference']);
        });

        Schema::table('minihouse_warehouse_stock_checks', function (Blueprint $table) {
            $table->dropForeign('mhw_chk_hand_user_fk');
            $table->dropColumn(['handover_status', 'handover_confirmed_by', 'handover_confirmed_at', 'handover_note']);
        });
    }
};

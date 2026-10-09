<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Chấm dứt hợp đồng (luồng tiền mới): ngưng bán ngay, giữ ký quỹ tối thiểu config('escrow.release_hold_days') ngày để nhận khiếu nại rồi mới được
    // hoàn — xem EscrowService::onContractTerminated() / releaseBlockers().
    public function up(): void
    {
        if (! Schema::hasColumn('partners', 'escrow_terminated_at')) {
            Schema::table('partners', function (Blueprint $table) {
                $table->timestamp('escrow_terminated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('partners', 'escrow_terminated_at')) {
            Schema::table('partners', fn (Blueprint $table) => $table->dropColumn('escrow_terminated_at'));
        }
    }
};

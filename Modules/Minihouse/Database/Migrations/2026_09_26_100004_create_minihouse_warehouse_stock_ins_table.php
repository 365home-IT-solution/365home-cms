<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phiếu nhập kho — mirror WarehouseStockIn (Home). "code" duy nhất TOÀN HỆ THỐNG (không theo từng
// Toà nhà) — xem WarehouseStockIn::generateCode() (phải tự bỏ qua global scope 'activeBuilding' lúc
// kiểm tra trùng, cùng lý do Home phải bỏ qua scope 'partner').
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_warehouse_stock_ins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->foreign('building_id', 'mhw_sin_building_fk')->references('id')->on('categories')->cascadeOnDelete();
            $table->string('code')->unique();
            $table->dateTime('received_at');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by', 'mhw_sin_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_warehouse_stock_ins');
    }
};

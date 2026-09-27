<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phiếu xuất kho — mirror WarehouseStockOut (Home). "room_id" trỏ products.id (char36 UUID, MiniHouse
// Room = Product) — Phòng NHẬN vật tư, nullable (xuất dùng chung cho cả Toà nhà thì để trống).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_warehouse_stock_outs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->foreign('building_id', 'mhw_sout_building_fk')->references('id')->on('categories')->cascadeOnDelete();
            $table->string('code')->unique();
            $table->char('room_id', 36)->nullable();
            $table->foreign('room_id', 'mhw_sout_room_fk')->references('id')->on('products')->nullOnDelete();
            $table->string('issued_to')->nullable();
            $table->dateTime('issued_at');
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by', 'mhw_sout_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_warehouse_stock_outs');
    }
};

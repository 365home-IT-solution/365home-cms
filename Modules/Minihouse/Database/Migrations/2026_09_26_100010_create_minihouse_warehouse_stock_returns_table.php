<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phiếu hoàn trả kho — mirror WarehouseStockReturn (Home). Tạo từ nút "Hoàn trả" trên phiếu xuất
// (tham chiếu warehouse_stock_out_item_id ở dòng), HOẶC tạo tay cho vật tư dư tìm thấy (không tham
// chiếu phiếu xuất nào — không giới hạn số lượng trong trường hợp này).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_warehouse_stock_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->foreign('building_id', 'mhw_ret_building_fk')->references('id')->on('categories')->cascadeOnDelete();
            $table->string('code')->unique();
            $table->char('room_id', 36)->nullable();
            $table->foreign('room_id', 'mhw_ret_room_fk')->references('id')->on('products')->nullOnDelete();
            $table->string('returned_by')->nullable();
            $table->dateTime('returned_at');
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by', 'mhw_ret_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_warehouse_stock_returns');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phiếu kiểm kê — mirror WarehouseStockCheck (Home), KHÔNG mang theo tính năng "xác nhận bàn giao ca"
// (handover_status/...) của bản Home — MiniHouse không có nghiệp vụ ca trực, bỏ để đơn giản hoá.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_warehouse_stock_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->foreign('building_id', 'mhw_chk_building_fk')->references('id')->on('categories')->cascadeOnDelete();
            $table->string('code')->unique();
            $table->dateTime('checked_at');
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by', 'mhw_chk_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_warehouse_stock_checks');
    }
};

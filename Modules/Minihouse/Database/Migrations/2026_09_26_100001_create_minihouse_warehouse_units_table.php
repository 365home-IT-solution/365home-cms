<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Đơn vị tính vật tư — DÙNG CHUNG mọi Toà nhà, cùng lý do như minihouse_warehouse_categories.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_warehouse_units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_warehouse_units');
    }
};

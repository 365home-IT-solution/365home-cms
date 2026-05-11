<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('additional_services', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Tên dịch vụ');
            $table->bigInteger('price')->default(0)->comment('Giá dịch vụ (VNĐ)');
            $table->string('image')->nullable()->comment('Đường dẫn ảnh (storage/...)');
            $table->boolean('is_active')->default(true)->comment('1 = hiển thị, 0 = ẩn');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('additional_services');
    }
};

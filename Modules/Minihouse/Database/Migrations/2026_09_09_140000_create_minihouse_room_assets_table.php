<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Theo dõi tài sản/nội thất GẮN VỚI TỪNG PHÒNG (tủ lạnh, máy lạnh, giường, tủ...) — trước đây không
// có nơi nào ghi nhận phòng nào có sẵn gì, tình trạng ra sao, dẫn tới không biết tài sản nào cần thay
// khi khách trả phòng, hoặc bị khách làm hư mà không có căn cứ đối chiếu lúc bàn giao.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_room_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('minihouse_rooms')->cascadeOnDelete();
            $table->string('name');
            $table->string('condition')->default('tot');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_room_assets');
    }
};

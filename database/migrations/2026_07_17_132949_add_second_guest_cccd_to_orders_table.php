<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Luật Cư trú (hiệu lực 01/07/2026) yêu cầu khai báo lưu trú ĐỦ TỪNG NGƯỜI khi lưu trú
            // qua đêm — đơn qua đêm có 2 khách phải quét CCCD + lưu SĐT của CẢ 2 người, không chỉ
            // người đặt phòng (buyer_name/buyer_phone/cccd_front/cccd_back/cccd_data ở trên vẫn là
            // của khách CHÍNH — người thứ 2 lưu riêng ở đây).
            $table->text('cccd_front_2')->nullable()->after('cccd_data');
            $table->text('cccd_back_2')->nullable()->after('cccd_front_2');
            $table->longText('cccd_data_2')->nullable()->after('cccd_back_2');
            $table->string('buyer_phone_2')->nullable()->after('cccd_data_2');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['cccd_front_2', 'cccd_back_2', 'cccd_data_2', 'buyer_phone_2']);
        });
    }
};

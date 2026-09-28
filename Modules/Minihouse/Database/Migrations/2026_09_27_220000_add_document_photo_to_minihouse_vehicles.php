<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ảnh giấy tờ xe (cà-vẹt/đăng ký xe) — 1 ảnh duy nhất, đúng yêu cầu giữ phần Xe đơn giản (biển số +
// loại xe + tên xe + ảnh giấy tờ), không thêm hãng/màu/vị trí/mã thẻ như bản trước.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_vehicles', function (Blueprint $table) {
            $table->string('document_photo')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_vehicles', function (Blueprint $table) {
            $table->dropColumn('document_photo');
        });
    }
};

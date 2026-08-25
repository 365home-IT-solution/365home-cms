<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Dịch vụ bổ sung (additional_services) là danh mục độc lập (không gắn 1 product_id cụ thể —
    // gán vào nhiều phòng qua bảng pivot room_additional_service_assigns), giống room_amenities —
    // nên cần partner_id riêng để mỗi đối tác tự tạo dịch vụ của mình, không dùng chung.
    public function up(): void
    {
        if (Schema::hasColumn('additional_services', 'partner_id')) {
            return;
        }

        Schema::table('additional_services', function (Blueprint $table) {
            $table->uuid('partner_id')->nullable()->after('id');
            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('additional_services', 'partner_id')) {
            return;
        }

        Schema::table('additional_services', function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
            $table->dropColumn('partner_id');
        });
    }
};

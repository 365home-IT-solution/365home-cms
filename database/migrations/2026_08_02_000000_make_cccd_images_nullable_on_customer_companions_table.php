<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cho phép tạo companion bằng nhập tay (full_name/cccd/dob/gender/address) không kèm ảnh —
        // trước đây cccd_front/cccd_back bắt buộc vì companion chỉ tạo được qua luồng quét CCCD.
        Schema::table('customer_companions', function (Blueprint $table) {
            $table->string('cccd_front')->nullable()->change();
            $table->string('cccd_back')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customer_companions', function (Blueprint $table) {
            $table->string('cccd_front')->nullable(false)->change();
            $table->string('cccd_back')->nullable(false)->change();
        });
    }
};

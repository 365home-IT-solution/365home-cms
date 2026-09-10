<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Khu vực" — cấp gộp nhóm PHÍA TRÊN Toà nhà, dùng khi 1 chủ/đơn vị quản lý nhiều toà nhà ở các vị
// trí địa lý khác nhau (VD "Khu Quận 3" gồm nhiều toà). Cho phép: (1) gán 1 tài khoản quản lý CẢ 1
// khu vực thay vì tick tay từng toà (tự bao gồm cả toà thêm sau này vào khu — xem
// User::rootBuildingIds()), (2) lọc nhanh danh sách toà nhà theo khu vực khi số toà nhà lớn.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_zones');
    }
};

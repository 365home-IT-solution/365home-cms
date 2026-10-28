<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Khu vực hiển thị của chi nhánh trong tỉnh (VD "Đà Lạt", "Phan Thiết") — sau sáp nhập tỉnh 2025,
// 1 tỉnh gộp nhiều thành phố cũ nên "Các chi nhánh tại Tỉnh Lâm Đồng" quá rộng với khách.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('province_branches', function (Blueprint $table) {
            $table->string('area_name', 100)->nullable()->after('ward_code');
        });
    }

    public function down(): void
    {
        Schema::table('province_branches', function (Blueprint $table) {
            $table->dropColumn('area_name');
        });
    }
};

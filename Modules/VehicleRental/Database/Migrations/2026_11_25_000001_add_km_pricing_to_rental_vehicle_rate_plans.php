<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Gói giá "theo km" (unit = km): price = đồng/km, min_km = số km tối thiểu tính tiền. Khách chọn số km dự kiến lúc đặt, tiền chốt theo km thực tế lúc trả.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_vehicle_rate_plans', function (Blueprint $table) {
            $table->unsignedInteger('min_km')->nullable()->after('extra_km_price')->comment('Gói theo km: số km tối thiểu tính tiền');
        });
    }

    public function down(): void
    {
        Schema::table('rental_vehicle_rate_plans', function (Blueprint $table) {
            $table->dropColumn('min_km');
        });
    }
};

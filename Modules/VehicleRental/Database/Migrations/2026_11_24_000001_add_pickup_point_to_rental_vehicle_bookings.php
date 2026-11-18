<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Điểm giao xe = vị trí HIỆN TẠI của xe theo GPS (không cố định ở chi nhánh). Lưu ảnh chụp lúc đặt xe; xe chưa ghép GPS / chưa có vị trí thì lấy chi nhánh.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_vehicle_bookings', function (Blueprint $table) {
            $table->string('pickup_source', 10)->nullable()->after('return_branch_id')->comment('vehicle | branch');
            $table->string('pickup_address')->nullable()->after('pickup_source');
            $table->decimal('pickup_latitude', 10, 7)->nullable()->after('pickup_address');
            $table->decimal('pickup_longitude', 10, 7)->nullable()->after('pickup_latitude');
            $table->dateTime('pickup_located_at')->nullable()->after('pickup_longitude')->comment('Thời điểm GPS của vị trí này');
        });
    }

    public function down(): void
    {
        Schema::table('rental_vehicle_bookings', function (Blueprint $table) {
            $table->dropColumn(['pickup_source', 'pickup_address', 'pickup_latitude', 'pickup_longitude', 'pickup_located_at']);
        });
    }
};

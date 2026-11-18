<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Liên kết GPS Motrak với xe thuê: ảnh chụp vị trí/km GPS lúc giao và nhận xe, đối chiếu km, cảnh báo GPS.
// Ánh xạ xe thuê ↔ thiết bị GPS nằm ở motrak_vehicles.rental_vehicle_id (module Motrak), không lặp lại ở đây.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_vehicle_handovers', function (Blueprint $table) {
            $table->unsignedBigInteger('gps_motrak_vehicle_id')->nullable()->after('handled_at');
            $table->decimal('gps_latitude', 10, 7)->nullable()->after('gps_motrak_vehicle_id');
            $table->decimal('gps_longitude', 10, 7)->nullable()->after('gps_latitude');
            $table->decimal('gps_kmage', 14, 3)->nullable()->after('gps_longitude')->comment('Km tích luỹ GPS tại thời điểm giao/nhận (không phải odometer)');
            $table->dateTime('gps_time')->nullable()->after('gps_kmage');
            $table->string('gps_address')->nullable()->after('gps_time');
            $table->boolean('gps_fresh')->nullable()->after('gps_address')->comment('Điểm GPS còn mới (trong ngưỡng) lúc chụp');
            $table->unsignedInteger('gps_branch_distance_m')->nullable()->after('gps_fresh')->comment('Khoảng cách tới chi nhánh (m) lúc giao/nhận');
        });

        Schema::table('rental_vehicle_bookings', function (Blueprint $table) {
            $table->decimal('gps_km_driven', 10, 3)->nullable()->after('km_driven')->comment('Km theo GPS (kmage nhận - kmage giao)');
            $table->decimal('gps_km_discrepancy_pct', 7, 2)->nullable()->after('gps_km_driven')->comment('Lệch % giữa km GPS và km odometer');
        });

        Schema::create('rental_vehicle_gps_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->foreign('vehicle_id', 'rvga_vehicle_fk')->references('id')->on('rental_vehicles')->cascadeOnDelete();
            $table->unsignedBigInteger('booking_id')->nullable()->comment('Đơn đang thuê tại thời điểm cảnh báo (nếu có)');
            $table->string('type', 30)->comment('out_of_area | speeding | unauthorized_use | offline_during_rental | return_far_from_branch | km_discrepancy');
            $table->string('severity', 10)->default('warning')->comment('warning | danger');
            $table->string('message');
            $table->json('data')->nullable();
            $table->unsignedBigInteger('motrak_vehicle_id')->nullable();
            $table->dateTime('gps_time')->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->string('acknowledged_by', 36)->nullable()->comment('users.id (UUID)');
            $table->timestamps();

            $table->index(['vehicle_id', 'type', 'created_at'], 'rvga_vehicle_type_idx');
            $table->index('acknowledged_at', 'rvga_ack_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_vehicle_gps_alerts');
        Schema::table('rental_vehicle_bookings', function (Blueprint $table) {
            $table->dropColumn(['gps_km_driven', 'gps_km_discrepancy_pct']);
        });
        Schema::table('rental_vehicle_handovers', function (Blueprint $table) {
            $table->dropColumn(['gps_motrak_vehicle_id', 'gps_latitude', 'gps_longitude', 'gps_kmage', 'gps_time', 'gps_address', 'gps_fresh', 'gps_branch_distance_m']);
        });
    }
};

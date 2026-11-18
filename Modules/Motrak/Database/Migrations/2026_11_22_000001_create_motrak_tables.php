<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tích hợp GPS Motrak. Token/cấu hình KHÔNG nằm ở đây mà ở hệ thống cấu hình chung (Spatie Settings, nhóm "motrak", mã hoá khi lưu).
// Tên khoá ngoại đặt ngắn tường minh vì tiền tố bảng "cms_" làm tên tự sinh vượt 64 ký tự của MySQL.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motrak_vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('vehicle_id', 40)->comment('vehicleId của Motrak');
            $table->string('plate', 30);
            $table->string('plate_normalized', 30)->comment('Biển số chuẩn hoá (chữ hoa, bỏ ký tự phân cách) để khớp với xe thuê');
            $table->string('imei', 20)->nullable()->comment('Chỉ do quản trị viên ánh xạ; API refreshvehicles chưa xác nhận có IMEI');
            $table->unsignedBigInteger('rental_vehicle_id')->nullable()->comment('Ánh xạ sang rental_vehicles.id (xe cho thuê); không FK để module độc lập');

            // Vị trí MỚI NHẤT (chỉ cập nhật khi toạ độ hợp lệ — không bao giờ ghi đè bằng điểm 0,0/null)
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('speed_kmh')->nullable();
            $table->smallInteger('heading')->nullable();
            $table->decimal('kmage', 14, 3)->nullable()->comment('Km tích luỹ do GPS (không phải odometer của xe)');
            $table->decimal('day_km', 10, 3)->nullable();
            $table->unsignedInteger('day_idle_seconds')->nullable();
            $table->unsignedInteger('day_run_seconds')->nullable();
            $table->string('address')->nullable();
            $table->string('administrative_area', 100)->nullable();
            $table->integer('device_status')->nullable()->comment('"status" thô của Motrak — chưa xác nhận ý nghĩa');
            $table->boolean('is_connected')->nullable()->comment('"isConnected" thô của Motrak — chưa xác nhận ý nghĩa');
            $table->dateTime('gps_time')->nullable()->comment('Giờ GPS (đổi từ utcTicks, múi giờ app)');
            $table->unsignedBigInteger('utc_ticks')->nullable();
            $table->unsignedBigInteger('device_time_raw')->nullable()->comment('"time" thô (giây kể từ 2010-01-01 giờ UTC+7)');
            $table->dateTime('last_stopped_at')->nullable();
            $table->json('raw_data')->nullable()->comment('Bản ghi thô mới nhất từ Motrak (đã bỏ trường nhạy cảm nếu có)');

            $table->dateTime('last_synced_at')->nullable();
            $table->boolean('in_last_sync')->default(true)->comment('Có trong lần đồng bộ thành công gần nhất không');
            $table->dateTime('last_history_at')->nullable();
            $table->dateTime('first_seen_at')->nullable();
            $table->timestamps();

            $table->unique('vehicle_id', 'mtv_vehicle_id_uq');
            $table->unique('imei', 'mtv_imei_uq');
            $table->unique('rental_vehicle_id', 'mtv_rental_vehicle_uq');
            $table->index('plate_normalized', 'mtv_plate_idx');
            $table->index('gps_time', 'mtv_gps_time_idx');
        });

        Schema::create('motrak_vehicle_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('motrak_vehicle_id');
            $table->foreign('motrak_vehicle_id', 'mtl_vehicle_fk')->references('id')->on('motrak_vehicles')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('speed_kmh')->nullable();
            $table->smallInteger('heading')->nullable();
            $table->decimal('kmage', 14, 3)->nullable();
            $table->string('address')->nullable();
            $table->string('administrative_area', 100)->nullable();
            $table->integer('device_status')->nullable();
            $table->boolean('is_connected')->nullable();
            $table->dateTime('gps_time');
            $table->unsignedBigInteger('utc_ticks');
            $table->dateTime('created_at')->nullable();

            $table->unique(['motrak_vehicle_id', 'utc_ticks'], 'mtl_vehicle_ticks_uq');
            $table->index(['motrak_vehicle_id', 'gps_time'], 'mtl_vehicle_time_idx');
            $table->index('gps_time', 'mtl_time_idx');
        });

        Schema::create('motrak_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('sync_type', 20)->comment('manual | scheduled | test');
            $table->string('status', 20)->comment('success | partial | failed | skipped');
            $table->unsignedInteger('total_vehicles')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('new_locations')->default(0);
            $table->string('error_type', 30)->nullable()->comment('unauthorized | not_found | rate_limited | server_error | no_response | connection | invalid_response | not_configured | token_unreadable');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error_message')->nullable();
            $table->string('triggered_by', 36)->nullable()->comment('users.id (UUID) nếu bấm tay');
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['sync_type', 'started_at'], 'mts_type_started_idx');
            $table->index('started_at', 'mts_started_idx');
        });

        // Vai trò xem dữ liệu GPS (không được đổi Token — việc đó chỉ Super Admin).
        if (Schema::hasTable('roles')) {
            \App\Models\Role::query()->firstOrCreate(['name' => 'Quản lý GPS', 'guard_name' => 'web']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('motrak_vehicle_locations');
        Schema::dropIfExists('motrak_sync_logs');
        Schema::dropIfExists('motrak_vehicles');
    }
};

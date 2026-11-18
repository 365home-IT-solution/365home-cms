<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cho thuê xe của 365Home: xe, gói giá, đơn thuê, khoảng khoá xe. Tên khoá ngoại đặt ngắn tường minh (rv_*) vì tiền
// tố bảng "cms_" làm tên tự sinh vượt giới hạn 64 ký tự của MySQL. customer_id là UUID (customers.id) nên không có FK.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_vehicles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->comment('categories.id - chi nhánh nhận/trả xe');
            $table->foreign('branch_id', 'rv_branch_fk')->references('id')->on('categories')->restrictOnDelete();
            $table->string('type', 20)->comment('motorbike | car');
            $table->string('brand', 100);
            $table->string('model', 100);
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('plate', 20)->comment('Biển số đã chuẩn hoá: chữ hoa, bỏ ký tự phân cách');
            $table->string('plate_display', 30);
            $table->unsignedTinyInteger('seats')->nullable();
            $table->string('transmission', 20)->nullable()->comment('manual | auto');
            $table->string('fuel', 20)->nullable()->comment('petrol | diesel | electric | hybrid');
            $table->string('color', 50)->nullable();
            $table->string('image')->nullable();
            $table->json('photos')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('available')->comment('available | maintenance | inactive');
            $table->unsignedInteger('odometer_km')->default(0);
            $table->unsignedInteger('deposit_amount')->default(0)->comment('Cọc thế chấp thu tại quầy, không tính vào doanh thu');
            $table->date('registration_expires_on')->nullable()->comment('Hạn đăng kiểm');
            $table->date('insurance_expires_on')->nullable()->comment('Hạn bảo hiểm TNDS');
            $table->string('gps_device_id', 60)->nullable()->comment('Dành cho giai đoạn GPS');
            $table->unsignedInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->unique('plate', 'rv_plate_uq');
            $table->index(['branch_id', 'type', 'status'], 'rv_branch_type_status_idx');
        });

        Schema::create('rental_vehicle_rate_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->foreign('vehicle_id', 'rvrp_vehicle_fk')->references('id')->on('rental_vehicles')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('unit', 10)->comment('hour (gói giờ) | day (gói ngày 24h)');
            $table->unsignedSmallInteger('duration_hours')->comment('Gói giờ: 4/8/12...; gói ngày: 24');
            $table->unsignedInteger('price');
            $table->unsignedInteger('weekend_price')->nullable()->comment('Giá thay thế khi ngày thuê rơi vào cuối tuần (chỉ gói ngày)');
            $table->unsignedInteger('included_km')->nullable()->comment('null = không giới hạn km');
            $table->unsignedInteger('extra_km_price')->default(0);
            $table->unsignedSmallInteger('overtime_grace_minutes')->default(30);
            $table->unsignedInteger('overtime_hour_price')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['vehicle_id', 'is_active'], 'rvrp_vehicle_active_idx');
        });

        Schema::create('rental_vehicle_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30);
            $table->unsignedBigInteger('vehicle_id');
            $table->foreign('vehicle_id', 'rvb_vehicle_fk')->references('id')->on('rental_vehicles')->restrictOnDelete();
            $table->string('customer_id', 36)->comment('customers.id (UUID) - bắt buộc đăng nhập');
            $table->unsignedBigInteger('room_order_id')->nullable()->comment('Có = thuê xe kèm đơn phòng');
            $table->unsignedBigInteger('pickup_branch_id');
            $table->unsignedBigInteger('return_branch_id');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->dateTime('actual_start_at')->nullable();
            $table->dateTime('actual_end_at')->nullable();
            $table->string('status', 20)->default('pending_payment')
                ->comment('pending_payment | confirmed | picked_up | returned | settled | cancelled | no_show');
            $table->dateTime('hold_expires_at')->nullable();
            $table->unsignedInteger('rental_amount')->default(0);
            $table->unsignedInteger('discount_amount')->default(0);
            $table->unsignedInteger('extra_amount')->default(0)->comment('Phát sinh sau chuyến (km, trễ giờ, hư hỏng...)');
            $table->unsignedInteger('deposit_amount')->default(0);
            $table->unsignedInteger('total_amount')->default(0);
            $table->unsignedInteger('paid_amount')->default(0);
            $table->json('pricing_snapshot')->nullable()->comment('Gói giá + báo giá lúc đặt; sửa giá trên CMS không đổi đơn cũ');
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique('code', 'rvb_code_uq');
            $table->index('customer_id', 'rvb_customer_idx');
            $table->index('room_order_id', 'rvb_room_order_idx');
            $table->index(['vehicle_id', 'start_at', 'end_at'], 'rvb_vehicle_time_idx');
            $table->index('status', 'rvb_status_idx');
        });

        Schema::create('rental_vehicle_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->foreign('vehicle_id', 'rvbl_vehicle_fk')->references('id')->on('rental_vehicles')->cascadeOnDelete();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('reason')->nullable();
            $table->string('created_by', 36)->nullable()->comment('users.id (UUID)');
            $table->timestamps();

            $table->index(['vehicle_id', 'start_at', 'end_at'], 'rvbl_vehicle_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_vehicle_blocks');
        Schema::dropIfExists('rental_vehicle_bookings');
        Schema::dropIfExists('rental_vehicle_rate_plans');
        Schema::dropIfExists('rental_vehicles');
    }
};

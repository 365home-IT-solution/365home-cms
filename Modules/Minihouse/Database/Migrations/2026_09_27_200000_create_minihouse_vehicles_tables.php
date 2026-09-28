<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Quản lý xe của khách thuê: bảng giá gửi xe THEO TOÀ NHÀ + LOẠI XE (phí tháng, giới hạn) và danh sách
// xe (gắn hợp đồng). Tên khoá ngoại đặt ngắn tường minh (mhv_*) vì tiền tố bảng "cms_" làm tên tự sinh
// vượt giới hạn 64 ký tự của MySQL.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_vehicle_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->foreign('building_id', 'mhv_rate_building_fk')->references('id')->on('categories')->cascadeOnDelete();
            $table->string('vehicle_type', 20);
            $table->decimal('monthly_fee', 12, 2)->default(0);
            $table->unsignedSmallInteger('max_per_contract')->nullable()->comment('null = không giới hạn');
            $table->unsignedSmallInteger('capacity')->nullable()->comment('Tổng số chỗ của toà cho loại xe này, null = không giới hạn');
            $table->timestamps();

            $table->unique(['building_id', 'vehicle_type'], 'mhv_rate_building_type_uq');
        });

        Schema::create('minihouse_vehicles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->foreign('building_id', 'mhv_veh_building_fk')->references('id')->on('categories')->cascadeOnDelete();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->foreign('contract_id', 'mhv_veh_contract_fk')->references('id')->on('minihouse_contracts')->nullOnDelete();
            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id', 'mhv_veh_tenant_fk')->references('id')->on('minihouse_tenants')->cascadeOnDelete();
            $table->string('plate', 20)->comment('Biển số đã chuẩn hoá: chữ hoa, bỏ ký tự phân cách');
            $table->string('plate_display', 30);
            $table->string('vehicle_type', 20);
            $table->string('brand', 100)->nullable();
            $table->string('color', 50)->nullable();
            $table->string('parking_slot', 50)->nullable();
            $table->string('tag_code', 100)->nullable();
            $table->decimal('monthly_fee', 12, 2)->nullable()->comment('Ghi đè phí theo bảng giá, null = dùng bảng giá');
            $table->string('status', 20)->default('pending')->comment('pending|active|inactive|rejected');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('photo')->nullable();
            $table->string('registration_photo')->nullable();
            $table->text('note')->nullable();
            $table->string('requested_by', 10)->default('staff')->comment('staff|tenant');
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['building_id', 'plate'], 'mhv_veh_building_plate_idx');
            $table->index(['contract_id', 'status'], 'mhv_veh_contract_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_vehicles');
        Schema::dropIfExists('minihouse_vehicle_rates');
    }
};

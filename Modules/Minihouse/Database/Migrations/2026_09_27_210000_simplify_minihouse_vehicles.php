<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Rút gọn phần Xe: chỉ còn biển số + loại xe (xe máy/ô tô) + tên xe. Bỏ hãng/màu/vị trí đậu/mã thẻ/ảnh/
// ghi chú/phí ghi đè riêng từng xe (phí chỉ theo bảng giá toà × loại xe). Xe đạp/xe đạp điện gộp về xe máy.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_vehicles', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->after('vehicle_type');
        });

        DB::table('minihouse_vehicles')->whereIn('vehicle_type', ['bicycle', 'ebike'])->update(['vehicle_type' => 'motorbike']);
        DB::table('minihouse_vehicle_rates')->whereIn('vehicle_type', ['bicycle', 'ebike'])->delete();

        Schema::table('minihouse_vehicles', function (Blueprint $table) {
            $table->dropColumn(['brand', 'color', 'parking_slot', 'tag_code', 'monthly_fee', 'photo', 'registration_photo', 'note']);
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_vehicles', function (Blueprint $table) {
            $table->string('brand', 100)->nullable();
            $table->string('color', 50)->nullable();
            $table->string('parking_slot', 50)->nullable();
            $table->string('tag_code', 100)->nullable();
            $table->decimal('monthly_fee', 12, 2)->nullable();
            $table->string('photo')->nullable();
            $table->string('registration_photo')->nullable();
            $table->text('note')->nullable();
            $table->dropColumn('name');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Đơn giá điện/nước MẶC ĐỊNH theo từng toà nhà — thường cố định theo toà (khác chuỗi nhà trọ khác
// nhau có thể áp giá khác nhau), để InvoiceForm tự điền sẵn khi tạo hoá đơn, không phải gõ tay lại
// mỗi tháng. Vẫn sửa được trên từng hoá đơn nếu tháng đó đổi giá.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->decimal('electric_unit_price', 12, 2)->nullable()->after('ward');
            $table->decimal('water_unit_price', 12, 2)->nullable()->after('electric_unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->dropColumn(['electric_unit_price', 'water_unit_price']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Đơn giá điện/nước RIÊNG của hợp đồng — mặc định lấy từ Toà nhà của phòng (ContractForm tự điền),
// nhưng có thể khác đi nếu thương lượng riêng với khách. InvoiceForm ưu tiên đọc từ đây trước, chỉ
// rơi về giá mặc định của Toà nhà khi hợp đồng chưa có giá riêng.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_contracts', function (Blueprint $table) {
            $table->decimal('electric_unit_price', 12, 2)->nullable()->after('deposit_amount');
            $table->decimal('water_unit_price', 12, 2)->nullable()->after('electric_unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_contracts', function (Blueprint $table) {
            $table->dropColumn(['electric_unit_price', 'water_unit_price']);
        });
    }
};

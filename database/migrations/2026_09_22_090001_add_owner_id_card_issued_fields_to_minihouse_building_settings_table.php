<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cặp "CCCD cấp ngày/nơi cấp" của BÊN A (chủ trọ) cho hợp đồng điện tử — cùng lý do và cùng cặp cột
// vừa thêm cho Tenant (BÊN B), xem 2026_09_22_090000_add_id_card_issued_fields_to_minihouse_tenants_
// table.php. Đặt ở minihouse_building_settings (KHÔNG phải bảng minihouse_buildings cũ đã ngừng
// dùng từ khi gộp Toà nhà vào categories — xem 2026_09_17_000002_create_minihouse_building_settings_
// table.php) vì Building::detail() đọc field owner_* từ đúng bảng này.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_building_settings', function (Blueprint $table) {
            $table->date('owner_id_card_issued_date')->nullable()->after('owner_id_card_number');
            $table->string('owner_id_card_issued_place')->nullable()->after('owner_id_card_issued_date');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_building_settings', function (Blueprint $table) {
            $table->dropColumn(['owner_id_card_issued_date', 'owner_id_card_issued_place']);
        });
    }
};

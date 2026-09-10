<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hồ sơ CHỦ SỞ HỮU đầy đủ theo đúng nghiệp vụ (giống mức chi tiết của Tenant) — trước đây chỉ có
// owner_name, không đủ để đưa vào hợp đồng thuê phòng (mục "BÊN CHO THUÊ") hay liên hệ khi cần. Xem
// ContractContentRenderer::render() — mục BÊN A sẽ tự điền từ đây thay vì để trống "...................".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->string('owner_phone')->nullable()->after('owner_name');
            $table->string('owner_id_card_number')->nullable()->after('owner_phone');
            $table->string('owner_email')->nullable()->after('owner_id_card_number');
            $table->string('owner_address')->nullable()->after('owner_email');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->dropColumn(['owner_phone', 'owner_id_card_number', 'owner_email', 'owner_address']);
        });
    }
};

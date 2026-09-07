<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lưu Tỉnh/Thành phố + Phường/Xã dạng "display" (VD "01 - Thành phố Hà Nội") — ĐÚNG format cột
// residence_declarations.province/ward đang dùng (tham chiếu App\Models\TbltProvince/TbltWard) —
// để tự điền thẳng cho "Khai báo lưu trú" không cần chuyển đổi qua lại. "address" hiện có coi là
// địa chỉ chi tiết (số nhà, đường) — không đổi ý nghĩa cột đó, chỉ bổ sung 2 cột phân loại hành
// chính còn thiếu.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->string('province')->nullable()->after('address');
            $table->string('ward')->nullable()->after('province');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->dropColumn(['province', 'ward']);
        });
    }
};

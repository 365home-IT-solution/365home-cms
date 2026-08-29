<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('price_boards', 'pricing_mode')) {
            return;
        }

        Schema::table('price_boards', function (Blueprint $table) {
            // 'override'   = nhập giá cụ thể thay thế hoàn toàn cho từng phòng (price_board_items).
            // 'adjustment' = 1 mức %/số tiền áp chung, tính từ giá gốc (bảng mặc định) của từng
            // phòng tại thời điểm áp dụng — không cần nhập lại giá từng phòng.
            $table->string('pricing_mode')->default('override')->after('is_active');
            $table->string('adjustment_type')->nullable()->after('pricing_mode');
            $table->decimal('adjustment_value', 10, 2)->nullable()->after('adjustment_type');
        });
    }

    public function down(): void
    {
        Schema::table('price_boards', function (Blueprint $table) {
            $table->dropColumn(['pricing_mode', 'adjustment_type', 'adjustment_value']);
        });
    }
};

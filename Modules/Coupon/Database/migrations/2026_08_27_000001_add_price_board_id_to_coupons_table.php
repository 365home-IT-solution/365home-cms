<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('coupons', 'price_board_id')) {
            return;
        }

        // Không constrained() — price_boards thuộc Modules/Product, thứ tự nạp module không đảm
        // bảo bảng đó luôn tồn tại trước khi migration này chạy. Chỉ cần cột + index.
        Schema::table('coupons', function (Blueprint $table) {
            $table->unsignedBigInteger('price_board_id')->nullable()->after('partner_id');
            $table->index('price_board_id');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropIndex(['price_board_id']);
            $table->dropColumn('price_board_id');
        });
    }
};

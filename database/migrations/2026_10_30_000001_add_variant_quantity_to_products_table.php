<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIẾN THỂ PHÒNG: 1 phòng (product) đại diện cho cả 1 LOẠI phòng gồm nhiều phòng giống hệt nhau (vd "DBL balcony" x5),
 * khách chọn số lượng phòng như Booking/Agoda thay vì mỗi phòng 101, 102... là 1 phòng riêng.
 * is_variant = false → phòng đơn như cũ (sức chứa 1). Xem App\Services\RoomInventory.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'is_variant')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('is_variant')->default(false);
                $table->unsignedSmallInteger('variant_quantity')->default(1);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'is_variant')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn(['is_variant', 'variant_quantity']);
            });
        }
    }
};

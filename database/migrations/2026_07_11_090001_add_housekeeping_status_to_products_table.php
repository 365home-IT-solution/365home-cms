<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'housekeeping_status')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            // available: sẵn sàng nhận đặt mới | cleaning: hết giờ đặt, đang chờ nhân viên dọn |
            // maintenance: đang bảo trì, tạm khóa khỏi vòng quay đặt phòng (không tự động dọn).
            $table->enum('housekeeping_status', ['available', 'cleaning', 'maintenance'])
                ->default('available')
                ->after('is_in_stock');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'housekeeping_status')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('housekeeping_status');
        });
    }
};

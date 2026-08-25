<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // room_services/room_specials/room_images KHÔNG cần cột partner_id riêng — chúng đã gắn trực
    // tiếp với 1 product_id/room_id cụ thể, và Product đã có partner_id, nên chỉ cần lọc qua quan
    // hệ product/room trong Resource. room_amenities thì khác: nó là danh mục độc lập (không có
    // product_id), gắn vào product qua bảng pivot product_amenity — nên cần partner_id riêng để
    // mỗi đối tác tự định nghĩa tiện ích của mình thay vì dùng chung 1 danh mục.
    public function up(): void
    {
        if (Schema::hasColumn('room_amenities', 'partner_id')) {
            return;
        }

        Schema::table('room_amenities', function (Blueprint $table) {
            $table->uuid('partner_id')->nullable()->after('id');
            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('room_amenities', 'partner_id')) {
            return;
        }

        Schema::table('room_amenities', function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
            $table->dropColumn('partner_id');
        });
    }
};

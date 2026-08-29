<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('room_time_slots', 'synced_from_price_board_id')) {
            return;
        }

        // Không dùng ->constrained() vì price_boards nằm ở migration khác module có thể chạy
        // sau tuỳ thứ tự nạp module — chỉ cần cột + index để tra cứu/khôi phục, không cần ràng
        // buộc khoá ngoại cứng ở đây.
        Schema::table('room_time_slots', function (Blueprint $table) {
            $table->unsignedBigInteger('synced_from_price_board_id')->nullable()->after('settings');
            $table->index('synced_from_price_board_id');
        });
    }

    public function down(): void
    {
        Schema::table('room_time_slots', function (Blueprint $table) {
            $table->dropIndex(['synced_from_price_board_id']);
            $table->dropColumn('synced_from_price_board_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tính lại products.rating_score từ room_ratings thật — một số phòng đang để 5.0 (seed/nhập tay)
 * dù chưa có đánh giá nào, làm card phòng và trang chi tiết hiện "5.0 sao · 0 đánh giá".
 * Phòng không có đánh giá → null. Về sau RatingController/RoomRating tự giữ đồng bộ.
 */
return new class extends Migration
{
    public function up(): void
    {
        $averages = DB::table('room_ratings')
            ->selectRaw('room_id, ROUND(AVG(star), 1) as avg_star')
            ->groupBy('room_id')
            ->pluck('avg_star', 'room_id');

        DB::table('products')->whereNotNull('rating_score')->whereNotIn('id', $averages->keys())->update(['rating_score' => null]);

        foreach ($averages as $roomId => $avg) {
            DB::table('products')->where('id', $roomId)->update(['rating_score' => $avg]);
        }
    }

    public function down(): void
    {
        // Không khôi phục điểm ảo.
    }
};

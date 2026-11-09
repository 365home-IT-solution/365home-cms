<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Cấu hình mini game Tiến lên (1 dòng duy nhất) — Super Admin chỉnh ở Filament hoặc /api/admin/games/tien-len/settings.
class TienLenSetting extends Model
{
    protected $fillable = [
        'is_enabled', 'turn_seconds', 'points_first', 'points_second', 'points_third', 'points_last', 'chop_bonus_points', 'daily_points_cap', 'default_card_deck',
    ];

    protected $casts = [
        'is_enabled'        => 'boolean',
        'turn_seconds'      => 'integer',
        'points_first'      => 'integer',
        'points_second'     => 'integer',
        'points_third'      => 'integer',
        'points_last'       => 'integer',
        'chop_bonus_points' => 'integer',
        'daily_points_cap'  => 'integer',
    ];

    // Luật validate dùng chung cho API admin và form Filament.
    public static function rules(): array
    {
        return [
            'is_enabled'        => 'sometimes|boolean',
            'turn_seconds'      => 'sometimes|integer|min:10|max:120',
            'points_first'      => 'sometimes|integer|min:0|max:100000',
            'points_second'     => 'sometimes|integer|min:0|max:100000',
            'points_third'      => 'sometimes|integer|min:0|max:100000',
            'points_last'       => 'sometimes|integer|min:0|max:100000',
            'chop_bonus_points' => 'sometimes|integer|min:0|max:100000',
            'daily_points_cap'  => 'sometimes|integer|min:0|max:10000000',
            // Bộ bài cho khách chưa tự chọn (xem TienLenDeckService::DECKS)
            'default_card_deck' => 'sometimes|string|in:' . implode(',', \App\Services\TienLenDeckService::keys()),
        ];
    }

    public static function current(): self
    {
        // refresh(): lấy giá trị mặc định từ DB cho dòng vừa tạo (server mới chưa có dòng nào), tránh thuộc tính null
        return static::query()->first() ?? static::create([])->refresh();
    }

    // Điểm theo hạng: hạng 1 = nhất, hạng cuối = bét, bàn 4 người mới có "ba".
    public function pointsForRank(int $rank, int $players): int
    {
        return match (true) {
            $rank === 1        => $this->points_first,
            $rank >= $players  => $this->points_last,
            $rank === 2        => $this->points_second,
            default            => $this->points_third,
        };
    }
}

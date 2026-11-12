<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Cấu hình mini game Tiến lên (1 dòng duy nhất) — Super Admin chỉnh ở Filament hoặc /api/admin/games/tien-len/settings.
class TienLenSetting extends Model
{
    protected $fillable = [
        'is_enabled', 'turn_seconds', 'points_first', 'points_second', 'points_third', 'points_last', 'daily_points_cap', 'default_card_deck',
        'unit_two_black', 'unit_two_red', 'unit_four', 'unit_pair_seq_3', 'unit_pair_seq_extra', 'unit_cong', 'unit_instant', 'points_per_unit',
    ];

    // Cột đơn vị phạt ⇔ khoá trong Rules::DEFAULT_UNITS
    public const UNIT_COLUMNS = [
        'two_black'      => 'unit_two_black',
        'two_red'        => 'unit_two_red',
        'four'           => 'unit_four',
        'pair_seq_3'     => 'unit_pair_seq_3',
        'pair_seq_extra' => 'unit_pair_seq_extra',
        'cong'           => 'unit_cong',
        'instant'        => 'unit_instant',
    ];

    protected $casts = [
        'is_enabled'        => 'boolean',
        'turn_seconds'      => 'integer',
        'points_first'      => 'integer',
        'points_second'     => 'integer',
        'points_third'      => 'integer',
        'points_last'       => 'integer',
        'daily_points_cap'  => 'integer',
        'unit_two_black'      => 'integer',
        'unit_two_red'        => 'integer',
        'unit_four'           => 'integer',
        'unit_pair_seq_3'     => 'integer',
        'unit_pair_seq_extra' => 'integer',
        'unit_cong'           => 'integer',
        'unit_instant'        => 'integer',
        'points_per_unit'     => 'integer',
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
            'daily_points_cap'  => 'sometimes|integer|min:0|max:10000000',
            // Đơn vị phạt (chặt / thối / cóng / tới trắng) và hệ số quy đổi sang điểm ví
            'unit_two_black'      => 'sometimes|integer|min:0|max:1000',
            'unit_two_red'        => 'sometimes|integer|min:0|max:1000',
            'unit_four'           => 'sometimes|integer|min:0|max:1000',
            'unit_pair_seq_3'     => 'sometimes|integer|min:0|max:1000',
            'unit_pair_seq_extra' => 'sometimes|integer|min:0|max:1000',
            'unit_cong'           => 'sometimes|integer|min:0|max:1000',
            'unit_instant'        => 'sometimes|integer|min:0|max:1000',
            'points_per_unit'     => 'sometimes|integer|min:0|max:100000',
            // Bộ bài cho khách chưa tự chọn (xem TienLenDeckService::DECKS)
            'default_card_deck' => 'sometimes|string|in:' . implode(',', \App\Services\TienLenDeckService::keys()),
        ];
    }

    public static function current(): self
    {
        // refresh(): lấy giá trị mặc định từ DB cho dòng vừa tạo (server mới chưa có dòng nào), tránh thuộc tính null
        return static::query()->first() ?? static::create([])->refresh();
    }

    /** @return array<string, int> đơn vị phạt theo khoá Rules::DEFAULT_UNITS — chốt vào ván lúc chia bài */
    public function penaltyUnits(): array
    {
        return array_map(fn (string $column) => (int) $this->{$column}, self::UNIT_COLUMNS);
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

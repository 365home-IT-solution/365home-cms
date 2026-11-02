<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Cấu hình mini game caro toàn hệ thống (1 dòng duy nhất) — Super Admin chỉnh ở trang "Caro trực tuyến" (App\Filament\Pages\CaroGameSettings).
class CaroSetting extends Model
{
    protected $fillable = [
        'is_enabled', 'board_size', 'win_length', 'block_two_ends', 'turn_seconds', 'match_wait_seconds',
        'invite_enabled', 'invite_expire_minutes', 'invite_wins_count', 'invite_share_url_base',
        'milestones', 'streak_days', 'invite_bonus_enabled', 'invite_bonus_daily_cap', 'reminder_enabled', 'reminder_hours_before',
        'max_caro_coupons_per_order', 'min_moves_to_count', 'daily_counted_wins_cap', 'same_opponent_daily_cap',
    ];

    protected $casts = [
        'is_enabled'              => 'boolean',
        'block_two_ends'          => 'boolean',
        'invite_enabled'          => 'boolean',
        'invite_wins_count'       => 'boolean',
        'invite_expire_minutes'   => 'integer',
        'board_size'              => 'integer',
        'win_length'              => 'integer',
        'turn_seconds'            => 'integer',
        'match_wait_seconds'      => 'integer',
        'milestones'              => 'array',
        'streak_days'             => 'integer',
        'invite_bonus_enabled'    => 'boolean',
        'invite_bonus_daily_cap'  => 'integer',
        'reminder_enabled'        => 'boolean',
        'reminder_hours_before'   => 'integer',
        'max_caro_coupons_per_order' => 'integer',
        'min_moves_to_count'      => 'integer',
        'daily_counted_wins_cap'  => 'integer',
        'same_opponent_daily_cap' => 'integer',
    ];

    public static function current(): self
    {
        return static::query()->first() ?? static::create([])->refresh();
    }

    /** @return array<int, array{wins: int, mode: string, prize_id: ?int}> Các mốc thưởng theo số trận tăng dần */
    public function sortedMilestones(): array
    {
        $milestones = array_map(fn (array $m) => [
            'wins'     => (int) $m['wins'],
            'mode'     => ($m['mode'] ?? 'random') === 'fixed' ? 'fixed' : 'random',
            'prize_id' => isset($m['prize_id']) ? (int) $m['prize_id'] : null,
        ], $this->milestones ?: [['wins' => 5, 'mode' => 'random']]);

        usort($milestones, fn (array $a, array $b) => $a['wins'] <=> $b['wins']);

        return $milestones;
    }

    // Số trận của 1 vòng = mốc lớn nhất; đạt mốc này thì tiến độ về 0
    public function cycleLength(): int
    {
        $milestones = $this->sortedMilestones();

        return max(1, $milestones[count($milestones) - 1]['wins']);
    }
}

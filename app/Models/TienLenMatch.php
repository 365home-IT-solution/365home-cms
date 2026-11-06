<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Một ván Tiến lên của khách với bot. state = App\Games\TienLen\Game::toArray() (có bài của bot, không bao giờ trả nguyên cho app).
class TienLenMatch extends Model
{
    use HasUlids;

    public const STATUS_PLAYING   = 'playing';
    public const STATUS_FINISHED  = 'finished';
    public const STATUS_ABANDONED = 'abandoned';

    public const STATUS_LABELS = [
        self::STATUS_PLAYING   => 'Đang chơi',
        self::STATUS_FINISHED  => 'Đã xong',
        self::STATUS_ABANDONED => 'Bỏ dở',
    ];

    protected $fillable = [
        'customer_id', 'room_id', 'player_count', 'seats', 'status', 'seq', 'event_count', 'state', 'is_first_match', 'instant_win',
        'human_rank', 'human_score', 'reward_points', 'turn_deadline', 'finished_at',
    ];

    protected $casts = [
        'state'          => 'array',
        'seats'          => 'array',
        'is_first_match' => 'boolean',
        'player_count'   => 'integer',
        'seq'            => 'integer',
        'event_count'    => 'integer',
        'human_rank'     => 'integer',
        'human_score'    => 'integer',
        'reward_points'  => 'integer',
        'turn_deadline'  => 'datetime',
        'finished_at'    => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(TienLenRoom::class, 'room_id');
    }

    public function players(): HasMany
    {
        return $this->hasMany(TienLenMatchPlayer::class, 'match_id')->orderBy('seat');
    }

    /**
     * Ghế theo thứ tự engine. Ván cũ (trước khi có cột seats) là ván 1 khách ngồi ghế 0 + bot.
     *
     * @return array<int, array{customer_id: ?string, name: string, is_bot: bool, room_seat: ?int, auto: bool, left: bool, timeouts: int}>
     */
    public function seatList(): array
    {
        if ($this->seats) {
            return $this->seats;
        }

        return array_map(fn (int $s) => [
            'customer_id' => $s === 0 ? $this->customer_id : null, 'name' => $s === 0 ? 'Bạn' : 'Máy ' . $s, 'is_bot' => $s !== 0,
            'room_seat' => null, 'auto' => false, 'left' => false, 'timeouts' => 0,
        ], range(0, $this->player_count - 1));
    }

    public function seatOf(string $customerId): ?int
    {
        foreach ($this->seatList() as $i => $seat) {
            if (($seat['customer_id'] ?? null) === $customerId) {
                return $i;
            }
        }

        return null;
    }

    public function moves(): HasMany
    {
        return $this->hasMany(TienLenMove::class, 'match_id')->orderBy('event_no');
    }
}

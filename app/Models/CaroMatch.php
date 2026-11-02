<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

// Một trận caro giữa 2 khách (source: queue = ghép ngẫu nhiên, invite = mời bạn). Lượt đi suy ra từ số nước đã đi (X đi trước).
class CaroMatch extends Model
{
    use HasUlids;

    public const STATUS_PLAYING  = 'playing';
    public const STATUS_FINISHED = 'finished';

    protected $fillable = [
        'player_x_id', 'player_o_id', 'source', 'board_size', 'win_length', 'block_two_ends', 'turn_seconds',
        'moves', 'status', 'winner', 'end_reason', 'winning_line', 'winner_customer_id', 'counted_for_reward',
        'turn_deadline_at', 'finished_at',
    ];

    protected $casts = [
        'block_two_ends'     => 'boolean',
        'counted_for_reward' => 'boolean',
        'moves'              => 'array',
        'winning_line'       => 'array',
        'turn_deadline_at'   => 'datetime',
        'finished_at'        => 'datetime',
    ];

    public function turn(): string
    {
        return count($this->moves) % 2 === 0 ? 'x' : 'o';
    }

    // Quân của khách trong trận này, null nếu không phải người chơi
    public function sideOf(string $customerId): ?string
    {
        return match ($customerId) {
            (string) $this->player_x_id => 'x',
            (string) $this->player_o_id => 'o',
            default                     => null,
        };
    }

    public function customerOf(string $side): ?string
    {
        return $side === 'x' ? $this->player_x_id : $this->player_o_id;
    }
}

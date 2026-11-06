<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

// Một trận caro giữa 2 hoặc 3 khách (source: queue = ghép ngẫu nhiên, invite = mời bạn). Thứ tự đi X → O → T (△, chỉ có ở trận 3 người),
// bỏ qua quân đã bị loại. Lượt đi suy ra từ quân của nước cuối cùng.
class CaroMatch extends Model
{
    use HasUlids;

    public const STATUS_PLAYING  = 'playing';
    public const STATUS_FINISHED = 'finished';

    public const SIDES = ['x', 'o', 't'];

    protected $fillable = [
        'player_x_id', 'player_o_id', 'player_t_id', 'player_count', 'source', 'board_size', 'win_length', 'block_two_ends', 'turn_seconds',
        'moves', 'eliminated', 'status', 'winner', 'end_reason', 'winning_line', 'winner_customer_id', 'counted_for_reward',
        'turn_deadline_at', 'finished_at',
    ];

    protected $attributes = [
        'player_count' => 2,
    ];

    protected $casts = [
        'player_count'       => 'integer',
        'block_two_ends'     => 'boolean',
        'counted_for_reward' => 'boolean',
        'moves'              => 'array',
        'eliminated'         => 'array',
        'winning_line'       => 'array',
        'turn_deadline_at'   => 'datetime',
        'finished_at'        => 'datetime',
    ];

    public function scopeOfPlayer(Builder $query, string $customerId): Builder
    {
        return $query->where(fn ($q) => $q->where('player_x_id', $customerId)->orWhere('player_o_id', $customerId)->orWhere('player_t_id', $customerId));
    }

    /** @return array<int, string> Các quân trong trận theo thứ tự đi */
    public function sides(): array
    {
        return array_slice(self::SIDES, 0, $this->player_count);
    }

    /** @return array<int, string> Các quân chưa bị loại */
    public function activeSides(): array
    {
        return array_values(array_diff($this->sides(), $this->eliminated ?? []));
    }

    public function isEliminated(?string $side): bool
    {
        return in_array($side, $this->eliminated ?? [], true);
    }

    // Quân của nước thứ $i — trận 3 người lưu [x, y, quân], trận 2 người chỉ lưu [x, y] (nước chẵn là X)
    public function moveSide(int $i): string
    {
        return $this->moves[$i][2] ?? ($i % 2 === 0 ? 'x' : 'o');
    }

    // Quân chưa bị loại kế tiếp sau quân vừa đi (chưa ai đi thì bắt đầu từ X)
    public function turn(): string
    {
        $sides  = $this->sides();
        $active = $this->activeSides();
        $count  = count($this->moves);
        $start  = $count === 0 ? 0 : (int) array_search($this->moveSide($count - 1), $sides, true) + 1;

        for ($i = 0; $i < count($sides); $i++) {
            $side = $sides[($start + $i) % count($sides)];
            if (in_array($side, $active, true)) {
                return $side;
            }
        }

        return $sides[0];
    }

    // Quân của khách trong trận này, null nếu không phải người chơi
    public function sideOf(string $customerId): ?string
    {
        return Arr::first($this->sides(), fn (string $side) => $customerId !== '' && (string) $this->customerOf($side) === $customerId);
    }

    public function customerOf(string $side): ?string
    {
        return match ($side) {
            'x'     => $this->player_x_id,
            'o'     => $this->player_o_id,
            't'     => $this->player_t_id,
            default => null,
        };
    }

    /** @return array<string, string> quân => khách, theo thứ tự đi */
    public function players(): array
    {
        return array_filter(array_combine($this->sides(), array_map(fn (string $side) => (string) $this->customerOf($side), $this->sides())));
    }
}

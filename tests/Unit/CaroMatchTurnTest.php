<?php

namespace Tests\Unit;

use App\Models\CaroMatch;
use PHPUnit\Framework\TestCase;

class CaroMatchTurnTest extends TestCase
{
    private function match(int $players, array $moves, array $eliminated = []): CaroMatch
    {
        return new CaroMatch([
            'player_x_id'  => 'a',
            'player_o_id'  => 'b',
            'player_t_id'  => $players === 3 ? 'c' : null,
            'player_count' => $players,
            'moves'        => $moves,
            'eliminated'   => $eliminated,
        ]);
    }

    public function test_two_player_turn_alternates_by_move_count(): void
    {
        $this->assertSame('x', $this->match(2, [])->turn());
        $this->assertSame('o', $this->match(2, [[1, 1]])->turn());
        $this->assertSame('x', $this->match(2, [[1, 1], [2, 2]])->turn());
    }

    public function test_three_player_turn_rotates_x_o_t(): void
    {
        $this->assertSame('x', $this->match(3, [])->turn());
        $this->assertSame('o', $this->match(3, [[1, 1, 'x']])->turn());
        $this->assertSame('t', $this->match(3, [[1, 1, 'x'], [2, 2, 'o']])->turn());
        $this->assertSame('x', $this->match(3, [[1, 1, 'x'], [2, 2, 'o'], [3, 3, 't']])->turn());
    }

    public function test_three_player_turn_skips_eliminated_side(): void
    {
        // O hết giờ ngay lượt mình → tới T
        $this->assertSame('t', $this->match(3, [[1, 1, 'x']], ['o'])->turn());
        // X bị loại trước khi đi nước đầu → O đi trước
        $this->assertSame('o', $this->match(3, [], ['x'])->turn());
        // T đầu hàng khi chưa tới lượt (đang lượt O) → vẫn lượt O, sau O quay về X
        $this->assertSame('o', $this->match(3, [[1, 1, 'x']], ['t'])->turn());
        $this->assertSame('x', $this->match(3, [[1, 1, 'x'], [2, 2, 'o']], ['t'])->turn());
    }

    public function test_players_and_side_lookup(): void
    {
        $match = $this->match(3, []);

        $this->assertSame(['x' => 'a', 'o' => 'b', 't' => 'c'], $match->players());
        $this->assertSame('t', $match->sideOf('c'));
        $this->assertNull($match->sideOf(''));
        $this->assertSame(['x' => 'a', 'o' => 'b'], $this->match(2, [])->players());
    }
}

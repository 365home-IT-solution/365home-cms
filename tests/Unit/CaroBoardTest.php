<?php

namespace Tests\Unit;

use App\Support\CaroBoard;
use PHPUnit\Framework\TestCase;

class CaroBoardTest extends TestCase
{
    // Dựng bàn từ danh sách quân theo phe (không cần đúng thứ tự lượt)
    private function board(array $x, array $o, int $size = 15): CaroBoard
    {
        $board = new CaroBoard($size);
        foreach ($x as [$cx, $cy]) {
            $board->place($cx, $cy, 'x');
        }
        foreach ($o as [$cx, $cy]) {
            $board->place($cx, $cy, 'o');
        }

        return $board;
    }

    public function test_five_in_a_row_wins_in_every_direction(): void
    {
        foreach ([[1, 0], [0, 1], [1, 1], [1, -1]] as [$dx, $dy]) {
            $stones = array_map(fn (int $i) => [5 + $dx * $i, 5 + $dy * $i], range(0, 4));
            $board  = $this->board($stones, []);

            $this->assertCount(5, $board->winningLine(5 + $dx * 2, 5 + $dy * 2, 5, false), "dx=$dx dy=$dy");
        }
    }

    public function test_four_in_a_row_does_not_win(): void
    {
        $board = $this->board([[0, 0], [1, 0], [2, 0], [3, 0]], []);

        $this->assertNull($board->winningLine(3, 0, 5, false));
    }

    public function test_block_two_ends_rule(): void
    {
        $board = $this->board([[2, 0], [3, 0], [4, 0], [5, 0], [6, 0]], [[1, 0], [7, 0]]);

        $this->assertNull($board->winningLine(4, 0, 5, true));
        $this->assertNotNull($board->winningLine(4, 0, 5, false));

        // Mép bàn không tính là bị chặn
        $edge = $this->board([[0, 0], [1, 0], [2, 0], [3, 0], [4, 0]], [[5, 0]]);
        $this->assertNotNull($edge->winningLine(0, 0, 5, true));
    }

    public function test_from_moves_alternates_sides(): void
    {
        $board = CaroBoard::fromMoves(15, [[7, 7], [8, 8], [7, 8]]);

        $this->assertSame('x', $board->at(7, 7));
        $this->assertSame('o', $board->at(8, 8));
        $this->assertSame('x', $board->at(7, 8));
        $this->assertNull($board->at(0, 0));
    }

    public function test_from_moves_uses_stored_side_for_three_players(): void
    {
        $board = CaroBoard::fromMoves(15, [[7, 7, 'x'], [8, 8, 'o'], [9, 9, 't'], [6, 6, 'o']]);

        $this->assertSame('x', $board->at(7, 7));
        $this->assertSame('t', $board->at(9, 9));
        $this->assertSame('o', $board->at(6, 6));
    }

    public function test_block_two_ends_by_two_different_opponents(): void
    {
        $board = new CaroBoard(15);
        foreach (range(2, 6) as $x) {
            $board->place($x, 0, 't');
        }
        $board->place(1, 0, 'x');
        $board->place(7, 0, 'o');

        $this->assertNull($board->winningLine(4, 0, 5, true));
        $this->assertCount(5, $board->winningLine(4, 0, 5, false));
    }
}

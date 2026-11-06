<?php

declare(strict_types=1);

namespace App\Support;

// Bàn cờ caro (2 hoặc 3 người) dựng lại từ danh sách nước đi — kiểm tra thắng. Không đụng DB, xem App\Services\CaroService.
// Toạ độ: x là cột, y là hàng, cùng chạy 0 .. size - 1.
class CaroBoard
{
    private const DIRECTIONS = [[1, 0], [0, 1], [1, 1], [1, -1]];

    /** @var array<int, array<int, string>> [y][x] => 'x' | 'o' | 't' */
    private array $cells = [];

    private int $count = 0;

    public function __construct(public readonly int $size) {}

    /** @param array<int, array{0: int, 1: int, 2?: string}> $moves Có phần tử thứ 3 (trận 3 người) thì là quân, không thì nước chẵn là X */
    public static function fromMoves(int $size, array $moves): self
    {
        $board = new self($size);
        foreach ($moves as $i => $move) {
            $board->place((int) $move[0], (int) $move[1], $move[2] ?? ($i % 2 === 0 ? 'x' : 'o'));
        }

        return $board;
    }

    public function place(int $x, int $y, string $side): void
    {
        $this->cells[$y][$x] = $side;
        $this->count++;
    }

    public function inBounds(int $x, int $y): bool
    {
        return $x >= 0 && $y >= 0 && $x < $this->size && $y < $this->size;
    }

    public function at(int $x, int $y): ?string
    {
        return $this->cells[$y][$x] ?? null;
    }

    public function isFull(): bool
    {
        return $this->count >= $this->size * $this->size;
    }

    /**
     * Đường thắng đi qua quân vừa đặt ở (x, y), null nếu chưa thắng. Luật chặn 2 đầu: hàng đủ quân mà cả 2 đầu là quân đối thủ (trận 3 người:
     * quân của bất kỳ ai khác, 2 đầu có thể là 2 người khác nhau) thì không tính (mép bàn không tính là bị chặn).
     *
     * @return array<int, array{0: int, 1: int}>|null
     */
    public function winningLine(int $x, int $y, int $winLength, bool $blockTwoEnds): ?array
    {
        $side = $this->at($x, $y);
        if ($side === null) {
            return null;
        }

        foreach (self::DIRECTIONS as [$dx, $dy]) {
            $line = [[$x, $y]];
            $ends = [];
            foreach ([1, -1] as $sign) {
                $cx = $x + $dx * $sign;
                $cy = $y + $dy * $sign;
                while ($this->inBounds($cx, $cy) && $this->at($cx, $cy) === $side) {
                    $line[] = [$cx, $cy];
                    $cx += $dx * $sign;
                    $cy += $dy * $sign;
                }
                $ends[] = $this->inBounds($cx, $cy) ? $this->at($cx, $cy) : null;
            }

            if (count($line) < $winLength) {
                continue;
            }

            // Đầu hàng dừng ở ô không phải quân mình nên khác null là quân người khác
            if ($blockTwoEnds && $ends[0] !== null && $ends[1] !== null) {
                continue;
            }

            usort($line, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

            return $line;
        }

        return null;
    }
}

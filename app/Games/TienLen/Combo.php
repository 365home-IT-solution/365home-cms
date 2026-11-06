<?php

declare(strict_types=1);

namespace App\Games\TienLen;

/**
 * Một bộ bài hợp lệ trong Tiến lên miền Nam.
 *  - single (rác)        1 lá
 *  - pair (đôi)          2 lá cùng giá trị
 *  - triple (sám)        3 lá cùng giá trị
 *  - straight (sảnh)     ≥ 3 lá liên tiếp, không có heo (A chỉ đứng cuối: Q-K-A được, K-A-2 / A-2-3 không)
 *  - pair_seq (đôi thông) ≥ 3 đôi liên tiếp, không có heo; length = số đôi
 *  - four (tứ quý)       4 lá cùng giá trị
 * length: số lá với sảnh, số đôi với đôi thông, còn lại là số lá. top: lá lớn nhất (quyết định thắng thua khi cùng loại).
 */
final class Combo
{
    public const SINGLE   = 'single';
    public const PAIR     = 'pair';
    public const TRIPLE   = 'triple';
    public const STRAIGHT = 'straight';
    public const PAIR_SEQ = 'pair_seq';
    public const FOUR     = 'four';

    public const LABELS = [
        self::SINGLE   => 'Rác',
        self::PAIR     => 'Đôi',
        self::TRIPLE   => 'Sám',
        self::STRAIGHT => 'Sảnh',
        self::PAIR_SEQ => 'Đôi thông',
        self::FOUR     => 'Tứ quý',
    ];

    /** @param int[] $cards đã sắp tăng dần */
    private function __construct(
        public readonly string $type,
        public readonly array $cards,
        public readonly int $length,
        public readonly int $top,
    ) {
    }

    /** @param int[] $cards */
    public static function detect(array $cards): ?self
    {
        $cards = array_values(array_unique($cards));
        sort($cards);
        $n = count($cards);

        if ($n === 0) {
            return null;
        }

        $top    = $cards[$n - 1];
        $groups = Card::groupByRank($cards);
        $ranks  = array_keys($groups);

        if (count($groups) === 1) {
            return match ($n) {
                1       => new self(self::SINGLE, $cards, 1, $top),
                2       => new self(self::PAIR, $cards, 2, $top),
                3       => new self(self::TRIPLE, $cards, 3, $top),
                4       => new self(self::FOUR, $cards, 4, $top),
                default => null,
            };
        }

        if (! self::consecutive($ranks) || max($ranks) === Card::RANK_TWO) {
            return null;
        }

        $sizes = array_values(array_unique(array_map('count', $groups)));

        if ($sizes === [1] && $n >= 3) {
            return new self(self::STRAIGHT, $cards, $n, $top);
        }

        if ($sizes === [2] && count($groups) >= 3) {
            return new self(self::PAIR_SEQ, $cards, count($groups), $top);
        }

        return null;
    }

    public function isSingleTwo(): bool
    {
        return $this->type === self::SINGLE && Card::isTwo($this->top);
    }

    public function isPairTwo(): bool
    {
        return $this->type === self::PAIR && Card::isTwo($this->top);
    }

    public function isTwo(): bool
    {
        return $this->isSingleTwo() || $this->isPairTwo();
    }

    // Hàng chặt: 3 đôi thông trở lên, tứ quý.
    public function isBomb(): bool
    {
        return $this->type === self::FOUR || $this->type === self::PAIR_SEQ;
    }

    public function count(): int
    {
        return count($this->cards);
    }

    public function label(): string
    {
        return match ($this->type) {
            self::PAIR_SEQ => $this->length . ' đôi thông',
            self::STRAIGHT => 'Sảnh ' . $this->length . ' lá',
            default        => self::LABELS[$this->type],
        };
    }

    public function toArray(): array
    {
        return ['type' => $this->type, 'length' => $this->length, 'top' => $this->top, 'cards' => $this->cards];
    }

    /** @param int[] $ranks đã sắp tăng dần */
    private static function consecutive(array $ranks): bool
    {
        sort($ranks);
        for ($i = 1, $n = count($ranks); $i < $n; $i++) {
            if ($ranks[$i] !== $ranks[$i - 1] + 1) {
                return false;
            }
        }

        return true;
    }
}

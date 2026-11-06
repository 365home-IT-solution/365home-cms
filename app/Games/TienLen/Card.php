<?php

declare(strict_types=1);

namespace App\Games\TienLen;

/**
 * Lá bài Tiến lên miền Nam mã hoá thành số 0..51: id = rank * 4 + suit, nên so 2 lá chỉ cần so id (giá trị trước, chất sau).
 *  - rank 0..12 = 3 4 5 6 7 8 9 10 J Q K A 2 (2 = heo, lớn nhất)
 *  - suit 0..3  = ♠ bích < ♣ chuồn < ♦ rô < ♥ cơ  (rô, cơ là heo đỏ; bích, chuồn là heo đen)
 */
final class Card
{
    public const RANKS = ['3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A', '2'];
    public const SUITS = ['S', 'C', 'D', 'H'];

    public const RANK_ACE = 11;
    public const RANK_TWO = 12;

    public static function rank(int $id): int
    {
        return intdiv($id, 4);
    }

    public static function suit(int $id): int
    {
        return $id % 4;
    }

    public static function isTwo(int $id): bool
    {
        return self::rank($id) === self::RANK_TWO;
    }

    public static function isRed(int $id): bool
    {
        return self::suit($id) >= 2;
    }

    public static function isValid(mixed $id): bool
    {
        return is_int($id) && $id >= 0 && $id <= 51;
    }

    // "3S", "10H", "2D"… — để app/log đọc cho dễ, API vẫn nhận id số.
    public static function code(int $id): string
    {
        return self::RANKS[self::rank($id)] . self::SUITS[self::suit($id)];
    }

    public static function fromCode(string $code): int
    {
        $code  = strtoupper(trim($code));
        $rank  = array_search(substr($code, 0, -1), self::RANKS, true);
        $suit  = array_search(substr($code, -1), self::SUITS, true);

        if ($rank === false || $suit === false) {
            throw new \InvalidArgumentException("Mã lá bài không hợp lệ: {$code}");
        }

        return $rank * 4 + $suit;
    }

    // "10♥" — hiển thị ở trang quản trị.
    public static function label(int $id): string
    {
        return self::RANKS[self::rank($id)] . ['♠', '♣', '♦', '♥'][self::suit($id)];
    }

    /** @param int[] $ids */
    public static function labels(array $ids): string
    {
        sort($ids);

        return implode(' ', array_map(fn (int $id) => self::label($id), $ids));
    }

    /** @param string[] $codes @return int[] */
    public static function fromCodes(array $codes): array
    {
        return array_map(fn (string $c) => self::fromCode($c), $codes);
    }

    /** @param int[] $ids @return array<int, array{id: int, code: string}> */
    public static function payload(array $ids): array
    {
        return array_map(fn (int $id) => ['id' => $id, 'code' => self::code($id)], array_values($ids));
    }

    /** @return int[] */
    public static function deck(): array
    {
        return range(0, 51);
    }

    /** @param int[] $ids @return array<int, int[]> rank => các lá (tăng dần) */
    public static function groupByRank(array $ids): array
    {
        $groups = [];
        sort($ids);
        foreach ($ids as $id) {
            $groups[self::rank($id)][] = $id;
        }

        return $groups;
    }
}

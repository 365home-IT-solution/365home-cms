<?php

declare(strict_types=1);

namespace App\Games\TienLen;

/**
 * Luật so bài / chặt / tới trắng / thối của Tiến lên miền Nam (bản 365 HOME đã chốt):
 *
 *  Chặn thường: cùng loại, cùng độ dài, lá lớn nhất cao hơn.
 *  Chặt (hàng chặt đánh đè lên bộ khác loại):
 *   - 3 đôi thông  chặt: 1 heo; 3 đôi thông nhỏ hơn (cùng loại)
 *   - Tứ quý       chặt: 1 heo, đôi heo, 3 đôi thông; tứ quý nhỏ hơn (cùng loại)
 *   - 4 đôi thông+ chặt: 1 heo, đôi heo, 3 đôi thông, tứ quý, đôi thông ngắn hơn; cùng độ dài thì lớn hơn
 *  Sám heo không có gì chặt được.
 *  Chặt ngoài lượt (kể cả đã bỏ lượt): chỉ 4 đôi thông trở lên, khi bộ trên bàn là heo hoặc hàng chặt — tứ quý KHÔNG được (đã chốt).
 *  Tới trắng (không tính đồng màu — đã chốt): sảnh rồng (3→A), tứ quý heo, 5 đôi thông, 6 đôi bất kỳ.
 */
final class Rules
{
    // Đơn vị tiền ván (chỉ để hiển thị điểm ván; điểm thưởng của khách tính theo hạng — xem TienLenSetting).
    public const UNIT_TWO_BLACK = 1;
    public const UNIT_TWO_RED   = 2;
    public const UNIT_FOUR      = 4;
    public const UNIT_CONG      = 2;   // cóng: đền thêm cho người về nhất, ngoài phần bét và phần thối
    public const UNIT_INSTANT   = 2;   // tới trắng: mỗi người còn lại trả người tới trắng

    // Điểm ván cơ bản theo hạng (nhất ăn bét, nhì ăn ba), index = hạng - 1.
    public const BASE_BY_PLAYERS = [
        2 => [1, -1],
        3 => [1, 0, -1],
        4 => [2, 1, -1, -2],
    ];

    public const INSTANT_DRAGON     = 'dragon';        // sảnh rồng
    public const INSTANT_FOUR_TWOS  = 'four_twos';     // tứ quý heo
    public const INSTANT_FIVE_PAIRS = 'five_pair_seq'; // 5 đôi thông
    public const INSTANT_SIX_PAIRS  = 'six_pairs';     // 6 đôi

    // Nhiều người cùng tới trắng: loại mạnh hơn thắng, cùng loại thì người gần lượt đi trước hơn thắng.
    public const INSTANT_PRIORITY = [
        self::INSTANT_DRAGON     => 4,
        self::INSTANT_FOUR_TWOS  => 3,
        self::INSTANT_FIVE_PAIRS => 2,
        self::INSTANT_SIX_PAIRS  => 1,
    ];

    public const INSTANT_LABELS = [
        self::INSTANT_DRAGON     => 'Sảnh rồng',
        self::INSTANT_FOUR_TWOS  => 'Tứ quý heo',
        self::INSTANT_FIVE_PAIRS => '5 đôi thông',
        self::INSTANT_SIX_PAIRS  => '6 đôi',
    ];

    public static function canBeat(Combo $prev, Combo $next): bool
    {
        if ($next->type === $prev->type && $next->length === $prev->length && $next->count() === $prev->count()) {
            return $next->top > $prev->top;
        }

        return self::chopsOtherType($prev, $next);
    }

    // Chặt = hàng chặt đánh lên heo hoặc lên hàng chặt khác (kể cả cùng loại lớn hơn) — dùng để tính tiền chặt / chặt chồng.
    public static function isChop(Combo $prev, Combo $next): bool
    {
        return $next->isBomb() && ($prev->isTwo() || $prev->isBomb()) && self::canBeat($prev, $next);
    }

    // Được chặt khi đã bỏ lượt / chưa tới lượt: chỉ 4 đôi thông trở lên.
    public static function canChopOutOfTurn(Combo $prev, Combo $next): bool
    {
        return $next->type === Combo::PAIR_SEQ && $next->length >= 4 && self::isChop($prev, $next);
    }

    // Giá trị của bộ bị chặt (cộng dồn khi chặt chồng): heo đen 1, heo đỏ 2, tứ quý 4, n đôi thông = 3 × (n − 2).
    public static function chopValue(Combo $chopped): int
    {
        if ($chopped->isTwo()) {
            return array_sum(array_map(fn (int $c) => Card::isRed($c) ? self::UNIT_TWO_RED : self::UNIT_TWO_BLACK, $chopped->cards));
        }

        return match ($chopped->type) {
            Combo::FOUR     => self::UNIT_FOUR,
            Combo::PAIR_SEQ => 3 * ($chopped->length - 2),
            default         => 0,
        };
    }

    /**
     * Thối: bài còn trên tay người thua có heo / hàng chặt. Đếm tứ quý trước, đôi thông dài nhất trong phần còn lại, rồi heo lẻ.
     *
     * @param int[] $hand
     * @return array{units: int, items: array<int, array{kind: string, cards: int[], units: int}>}
     */
    public static function leftoverPenalty(array $hand): array
    {
        $items  = [];
        $groups = Card::groupByRank($hand);

        foreach ($groups as $rank => $cards) {
            if (count($cards) === 4) {
                $items[] = ['kind' => $rank === Card::RANK_TWO ? 'four_twos' : 'four', 'cards' => $cards, 'units' => self::UNIT_FOUR];
                unset($groups[$rank]);
            }
        }

        // Đôi thông: dãy rank liên tiếp (không heo) mỗi rank còn ≥ 2 lá
        $run = [];
        $flush = function () use (&$run, &$items, $groups) {
            if (count($run) >= 3) {
                $cards = [];
                foreach ($run as $r) {
                    array_push($cards, ...array_slice($groups[$r], 0, 2));
                }
                $items[] = ['kind' => 'pair_seq', 'cards' => $cards, 'units' => 3 * (count($run) - 2)];
            }
            $run = [];
        };
        for ($r = 0; $r < Card::RANK_TWO; $r++) {
            if (isset($groups[$r]) && count($groups[$r]) >= 2) {
                $run[] = $r;
            } else {
                $flush();
            }
        }
        $flush();

        foreach ($groups[Card::RANK_TWO] ?? [] as $two) {
            $items[] = ['kind' => 'two', 'cards' => [$two], 'units' => Card::isRed($two) ? self::UNIT_TWO_RED : self::UNIT_TWO_BLACK];
        }

        return ['units' => array_sum(array_column($items, 'units')), 'items' => $items];
    }

    /** @param int[] $hand */
    public static function instantWin(array $hand): ?string
    {
        $groups = Card::groupByRank($hand);

        $hasDragon = true;
        for ($r = 0; $r <= Card::RANK_ACE; $r++) {
            if (! isset($groups[$r])) {
                $hasDragon = false;
                break;
            }
        }
        if ($hasDragon) {
            return self::INSTANT_DRAGON;
        }

        if (count($groups[Card::RANK_TWO] ?? []) === 4) {
            return self::INSTANT_FOUR_TWOS;
        }

        $run = 0;
        for ($r = 0; $r < Card::RANK_TWO; $r++) {
            $run = count($groups[$r] ?? []) >= 2 ? $run + 1 : 0;
            if ($run >= 5) {
                return self::INSTANT_FIVE_PAIRS;
            }
        }

        $pairs = array_sum(array_map(fn (array $g) => intdiv(count($g), 2), $groups));

        return $pairs >= 6 ? self::INSTANT_SIX_PAIRS : null;
    }

    private static function chopsOtherType(Combo $prev, Combo $next): bool
    {
        if ($next->type === Combo::PAIR_SEQ && $next->length === 3) {
            return $prev->isSingleTwo();
        }

        if ($next->type === Combo::FOUR) {
            return $prev->isTwo() || ($prev->type === Combo::PAIR_SEQ && $prev->length === 3);
        }

        if ($next->type === Combo::PAIR_SEQ && $next->length >= 4) {
            return $prev->isTwo()
                || $prev->type === Combo::FOUR
                || ($prev->type === Combo::PAIR_SEQ && $prev->length < $next->length);
        }

        return false;
    }
}

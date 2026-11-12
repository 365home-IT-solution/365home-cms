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
    // Đơn vị phạt mặc định (chặt / thối / cóng / tới trắng). Giá trị thật lấy từ TienLenSetting::penaltyUnits(),
    // chốt vào ván lúc chia bài (Game::$units) — đổi cài đặt giữa chừng không làm lệch ván đang chơi.
    public const UNIT_TWO_BLACK       = 1;
    public const UNIT_TWO_RED         = 2;
    public const UNIT_FOUR            = 4;
    public const UNIT_PAIR_SEQ_3      = 3;   // 3 đôi thông
    public const UNIT_PAIR_SEQ_EXTRA  = 3;   // mỗi đôi thông thêm (4 đôi thông = 3 + 3)
    public const UNIT_CONG            = 2;   // cóng: đền thêm cho người về nhất, ngoài phần bét và phần thối
    public const UNIT_INSTANT         = 2;   // tới trắng: mỗi người còn lại trả người tới trắng

    public const DEFAULT_UNITS = [
        'two_black'      => self::UNIT_TWO_BLACK,
        'two_red'        => self::UNIT_TWO_RED,
        'four'           => self::UNIT_FOUR,
        'pair_seq_3'     => self::UNIT_PAIR_SEQ_3,
        'pair_seq_extra' => self::UNIT_PAIR_SEQ_EXTRA,
        'cong'           => self::UNIT_CONG,
        'instant'        => self::UNIT_INSTANT,
    ];

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

    /** @param array<string, int>|null $units null = DEFAULT_UNITS; thiếu khoá nào lấy mặc định khoá đó */
    public static function units(?array $units = null): array
    {
        return array_map('intval', array_merge(self::DEFAULT_UNITS, array_intersect_key($units ?? [], self::DEFAULT_UNITS)));
    }

    // Giá trị của bộ bị chặt (cộng dồn khi chặt chồng): heo đen 1, heo đỏ 2, tứ quý 4, 3 đôi thông 3, mỗi đôi thêm +3 (mặc định).
    public static function chopValue(Combo $chopped, ?array $units = null): int
    {
        $u = self::units($units);
        if ($chopped->isTwo()) {
            return self::twosValue($chopped->cards, $u);
        }

        return match ($chopped->type) {
            Combo::FOUR     => $u['four'],
            Combo::PAIR_SEQ => self::pairSeqValue($chopped->length, $u),
            default         => 0,
        };
    }

    public static function pairSeqValue(int $pairs, ?array $units = null): int
    {
        $u = self::units($units);

        return $pairs < 3 ? 0 : $u['pair_seq_3'] + ($pairs - 3) * $u['pair_seq_extra'];
    }

    // Tên bộ dùng trong bảng điểm phạt: "heo đỏ", "đôi heo", "tứ quý", "3 đôi thông"…
    public static function penaltyLabel(Combo $c): string
    {
        if ($c->isSingleTwo()) {
            return Card::isRed($c->top) ? 'heo đỏ' : 'heo đen';
        }
        if ($c->isTwo()) {
            return 'đôi heo';
        }

        return mb_strtolower($c->label());
    }

    /** @param int[] $twos */
    private static function twosValue(array $twos, array $u): int
    {
        return array_sum(array_map(fn (int $c) => Card::isRed($c) ? $u['two_red'] : $u['two_black'], $twos));
    }

    /**
     * Thối: bài còn trên tay người thua có heo / hàng chặt. Đếm tứ quý trước, đôi thông dài nhất trong phần còn lại, rồi heo lẻ.
     *
     * @param int[] $hand
     * @return array{units: int, items: array<int, array{kind: string, cards: int[], units: int}>}
     */
    public static function leftoverPenalty(array $hand, ?array $units = null): array
    {
        $u      = self::units($units);
        $items  = [];
        $groups = Card::groupByRank($hand);

        foreach ($groups as $rank => $cards) {
            if (count($cards) === 4) {
                $items[] = ['kind' => $rank === Card::RANK_TWO ? 'four_twos' : 'four', 'cards' => $cards, 'units' => $u['four']];
                unset($groups[$rank]);
            }
        }

        // Đôi thông: dãy rank liên tiếp (không heo) mỗi rank còn ≥ 2 lá
        $run = [];
        $flush = function () use (&$run, &$items, $groups, $u) {
            if (count($run) >= 3) {
                $cards = [];
                foreach ($run as $r) {
                    array_push($cards, ...array_slice($groups[$r], 0, 2));
                }
                $items[] = ['kind' => 'pair_seq', 'cards' => $cards, 'units' => self::pairSeqValue(count($run), $u)];
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
            $items[] = ['kind' => 'two', 'cards' => [$two], 'units' => self::twosValue([$two], $u)];
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

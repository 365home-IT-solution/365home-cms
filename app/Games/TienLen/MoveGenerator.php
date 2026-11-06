<?php

declare(strict_types=1);

namespace App\Games\TienLen;

/**
 * Liệt kê các bộ có thể đánh từ 1 tay bài (dùng cho bot và gợi ý). Với sảnh / đôi thông chỉ sinh biến thể dùng lá nhỏ nhất cho các rank
 * dưới, còn rank trên cùng thử mọi chất — vì chỉ lá trên cùng quyết định thắng thua, nên vẫn phủ đủ mọi khả năng chặn.
 */
final class MoveGenerator
{
    /** @param int[] $hand @return Combo[] */
    public static function all(array $hand): array
    {
        $groups = Card::groupByRank($hand);
        $moves  = [];

        foreach ($groups as $cards) {
            foreach ($cards as $c) {
                $moves[] = [$c];
            }
            foreach ([2, 3, 4] as $k) {
                foreach (self::combinations($cards, $k) as $set) {
                    $moves[] = $set;
                }
            }
        }

        // Sảnh và đôi thông (không chứa heo)
        for ($start = 0; $start < Card::RANK_TWO; $start++) {
            for ($end = $start + 2; $end < Card::RANK_TWO; $end++) {
                if (! isset($groups[$end])) {
                    break;
                }

                $straightBase = [];
                for ($r = $start; $r < $end; $r++) {
                    if (! isset($groups[$r])) {
                        continue 3;
                    }
                    $straightBase[] = $groups[$r][0];
                }
                foreach ($groups[$end] as $topCard) {
                    $moves[] = [...$straightBase, $topCard];
                }
            }
        }

        for ($start = 0; $start < Card::RANK_TWO; $start++) {
            for ($end = $start + 2; $end < Card::RANK_TWO; $end++) {
                if (count($groups[$end] ?? []) < 2) {
                    break;
                }

                $base = [];
                for ($r = $start; $r < $end; $r++) {
                    if (count($groups[$r] ?? []) < 2) {
                        continue 3;
                    }
                    array_push($base, $groups[$r][0], $groups[$r][1]);
                }
                foreach (self::combinations($groups[$end], 2) as $topPair) {
                    $moves[] = [...$base, ...$topPair];
                }
            }
        }

        $combos = [];
        foreach ($moves as $cards) {
            $combo = Combo::detect($cards);
            if ($combo) {
                $combos[implode(',', $combo->cards)] = $combo;
            }
        }

        return array_values($combos);
    }

    /**
     * Các bộ được đánh lúc này: mở vòng (bắt buộc chứa $mustInclude nếu có) hoặc chặn bộ $prev.
     *
     * @param int[] $hand
     * @return Combo[]
     */
    public static function legal(array $hand, ?Combo $prev, ?int $mustInclude = null): array
    {
        return array_values(array_filter(self::all($hand), function (Combo $c) use ($prev, $mustInclude) {
            if ($prev === null) {
                return $mustInclude === null || in_array($mustInclude, $c->cards, true);
            }

            return Rules::canBeat($prev, $c);
        }));
    }

    /** @param int[] $items @return array<int, int[]> */
    private static function combinations(array $items, int $k): array
    {
        $n = count($items);
        if ($k > $n) {
            return [];
        }
        if ($k === $n) {
            return [$items];
        }

        $result = [];
        $walk = function (int $from, array $picked) use (&$walk, &$result, $items, $n, $k) {
            if (count($picked) === $k) {
                $result[] = $picked;

                return;
            }
            for ($i = $from; $i < $n; $i++) {
                $walk($i + 1, [...$picked, $items[$i]]);
            }
        };
        $walk(0, []);

        return $result;
    }
}

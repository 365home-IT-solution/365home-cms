<?php

declare(strict_types=1);

namespace App\Games\TienLen;

/**
 * Bot đánh Tiến lên kiểu người chơi phổ thông:
 *  - Đánh hết được trong 1 lần thì đánh luôn.
 *  - Mở vòng: xả bộ chứa lá nhỏ nhất (ưu tiên bộ dài để xả nhiều), giữ heo và hàng chặt; đối thủ còn 1 lá thì tránh đánh rác.
 *  - Chặn: dùng bộ nhỏ nhất không phá heo/hàng; chỉ ra heo khi bàn đã lên A trở lên hoặc đối thủ sắp về.
 *  - Có cơ hội chặt heo / chặt hàng là chặt (ăn tiền), kể cả chặt ngoài lượt bằng 4 đôi thông.
 */
final class Bot
{
    /** @return int[]|null null = bỏ lượt */
    public static function choose(Game $game, int $seat): ?array
    {
        $hand = $game->hands[$seat];
        $last = $game->lastCombo();

        if ($game->isInterrupt($seat)) {
            $chops = $game->outOfTurnChops($seat);

            return $chops ? self::smallest($chops)->cards : null;
        }

        $legal = $game->legalMoves($seat);
        if ($legal === []) {
            return null;
        }

        foreach ($legal as $c) {
            if ($c->count() === count($hand)) {
                return $c->cards;
            }
        }

        $minOpponent = self::minOpponentCards($game, $seat);
        $precious    = self::preciousCards($hand);
        $pressure    = $minOpponent <= 3 || count($hand) <= 4;
        $cheap       = array_values(array_filter($legal, fn (Combo $c) => ! $c->isBomb() && ! array_intersect($c->cards, $precious)));

        if ($last === null) {
            return self::lead($legal, $cheap, $hand, $minOpponent);
        }

        if ($last->isTwo() || $last->isBomb()) {
            $chops = array_values(array_filter($legal, fn (Combo $c) => Rules::isChop($last, $c)));
            if ($chops) {
                return self::smallest($chops)->cards;
            }
        }

        if ($cheap) {
            return self::smallest($cheap)->cards;
        }

        $normal = array_values(array_filter($legal, fn (Combo $c) => ! $c->isBomb()));
        if ($normal && ($pressure || Card::rank($last->top) >= Card::RANK_ACE)) {
            return self::smallest($normal)->cards;
        }

        if ($minOpponent <= 2) {
            return self::smallest($legal)->cards;
        }

        return null;
    }

    /** @param Combo[] $legal @param Combo[] $cheap @param int[] $hand */
    private static function lead(array $legal, array $cheap, array $hand, int $minOpponent): array
    {
        $pool = $cheap ?: (array_values(array_filter($legal, fn (Combo $c) => ! $c->isBomb())) ?: $legal);

        if ($minOpponent === 1) {
            $multi = array_values(array_filter($pool, fn (Combo $c) => $c->type !== Combo::SINGLE));
            if ($multi) {
                $pool = $multi;
            } else {
                return [max($hand)]; // chỉ còn rác: đánh lá lớn nhất để đối thủ khó chặn
            }
        }

        $lowest = min(array_merge(...array_map(fn (Combo $c) => $c->cards, $pool)));
        $withLowest = array_values(array_filter($pool, fn (Combo $c) => in_array($lowest, $c->cards, true)));

        usort($withLowest, fn (Combo $a, Combo $b) => [$b->count(), $a->top] <=> [$a->count(), $b->top]);

        return $withLowest[0]->cards;
    }

    /** @param Combo[] $combos */
    private static function smallest(array $combos): Combo
    {
        usort($combos, fn (Combo $a, Combo $b) => [Card::rank($a->top), $a->count(), $a->top] <=> [Card::rank($b->top), $b->count(), $b->top]);

        return $combos[0];
    }

    // Heo + các lá đang nằm trong hàng chặt (tứ quý, đôi thông) — để dành, không xả bừa.
    /** @param int[] $hand @return int[] */
    private static function preciousCards(array $hand): array
    {
        $precious = array_values(array_filter($hand, fn (int $c) => Card::isTwo($c)));

        foreach (MoveGenerator::all($hand) as $c) {
            if ($c->isBomb()) {
                array_push($precious, ...$c->cards);
            }
        }

        return array_values(array_unique($precious));
    }

    private static function minOpponentCards(Game $game, int $seat): int
    {
        $counts = [];
        foreach ($game->activeSeats() as $s) {
            if ($s !== $seat) {
                $counts[] = count($game->hands[$s]);
            }
        }

        return $counts ? min($counts) : 13;
    }
}

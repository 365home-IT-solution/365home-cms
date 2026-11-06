<?php

declare(strict_types=1);

namespace App\Games\TienLen;

/**
 * Một ván Tiến lên miền Nam 2–4 người, chạy hoàn toàn ở BE (app không bao giờ thấy bài người khác).
 *
 *  Chia: mỗi người 13 lá; bàn 2–3 người thì phần còn lại úp, không dùng.
 *  Đi trước: ván nối tiếp thì người về nhất ván trước đi tự do; ván đầu (hoặc đổi số người) thì người cầm lá nhỏ nhất bàn
 *            (4 người là 3♠) đi trước và bộ đầu tiên phải chứa lá đó.
 *  Vòng: người mở vòng không được bỏ; đã bỏ lượt thì mất quyền tới hết vòng (trừ chặt ngoài lượt bằng 4 đôi thông);
 *        mọi người khác bỏ hết thì người đánh cuối mở vòng mới — người đó vừa hết bài thì người kế tiếp còn bài được mở (hưởng sái).
 *  Chặt chồng: tiền chặt cộng dồn, người bị chặt cuối cùng chịu hết, chốt khi hết vòng.
 *  Cóng: lúc có người về nhất mà ai đó chưa đánh được lá nào ⇒ người đó cóng (bét, đền thêm cho người nhất + thối hết hàng).
 *  Kết thúc: còn ≤ 1 người cầm bài. Người thua còn heo / hàng chặt ⇒ thối cho người về ngay trên mình.
 *
 * Mọi hành động ghi vào $log (service đẩy xuống DB và trả cho app để chạy hiệu ứng).
 */
final class Game
{
    public const STATUS_PLAYING  = 'playing';
    public const STATUS_FINISHED = 'finished';

    public int $players;
    /** @var array<int, int[]> */
    public array $hands = [];
    /** @var array<int, int[]> */
    public array $initialHands = [];
    /** @var array<int, bool> */
    public array $playedAny = [];
    /** @var int[] thứ tự về (không gồm người cóng) */
    public array $finished = [];
    /** @var int[] */
    public array $cong = [];
    public int $turn = 0;
    /** @var array{seat: int, cards: int[]}|null */
    public ?array $lastPlay = null;
    /** @var int[] đã bỏ lượt trong vòng này */
    public array $passed = [];
    /** @var int[] đã từ chối chặt ngoài lượt bộ đang trên bàn */
    public array $declined = [];
    public ?int $mustInclude = null;
    /**
     * Ghế người thật trong phòng nhiều người: KHÔNG dừng ván để hỏi chặt ngoài lượt (dừng hỏi = lộ cho cả bàn biết ghế đó cầm 4 đôi thông).
     * Thay vào đó họ được tự đánh 4 đôi thông chặt bất cứ lúc nào bộ trên bàn còn chặt được — ai bấm trước người kế tiếp thì chặt được.
     *
     * @var int[]
     */
    public array $freeChopSeats = [];
    public bool $firstMatch = false;
    public int $chainValue = 0;
    /** @var array{chopper: int, victim: int, units: int}|null */
    public ?array $chopPending = null;
    /** @var array<int, array{chopper: int, victim: int, units: int}> */
    public array $chops = [];
    public string $status = self::STATUS_PLAYING;
    /** @var array{seat: int, kind: string}|null */
    public ?array $instantWin = null;
    public ?array $result = null;
    /** @var array<int, array> sự kiện phát sinh từ lần flush gần nhất */
    public array $log = [];

    /**
     * @param int|null $leader người về nhất ván trước (cùng bàn) — null = ván đầu
     * @param int[]|null $deck bộ bài đã trộn sẵn (test); null = trộn ngẫu nhiên an toàn
     */
    public static function deal(int $players, ?int $leader = null, ?array $deck = null): self
    {
        if ($players < 2 || $players > 4) {
            throw new GameException('Tiến lên chỉ chơi từ 2 đến 4 người.');
        }

        $deck ??= self::shuffle(Card::deck());

        $game          = new self();
        $game->players = $players;

        for ($s = 0; $s < $players; $s++) {
            $hand = array_slice($deck, $s * 13, 13);
            sort($hand);
            $game->hands[$s]     = $hand;
            $game->playedAny[$s] = false;
        }
        $game->initialHands = $game->hands;

        if ($leader !== null && $leader >= 0 && $leader < $players) {
            $game->turn = $leader;
        } else {
            $lowest = min(array_merge(...$game->hands));
            foreach ($game->hands as $s => $hand) {
                if (in_array($lowest, $hand, true)) {
                    $game->turn = $s;
                }
            }
            $game->mustInclude = $lowest;
            $game->firstMatch  = true;
        }

        $game->log[] = ['type' => 'deal', 'seat' => $game->turn, 'must_include' => $game->mustInclude];
        $game->checkInstantWin();

        return $game;
    }

    // Người đang phải ra quyết định: người có quyền chặt ngoài lượt (đã bỏ lượt, cầm 4 đôi thông chặt được) được hỏi trước, sau đó tới người đến lượt.
    public function actor(): ?int
    {
        if ($this->status !== self::STATUS_PLAYING) {
            return null;
        }

        $last = $this->lastCombo();
        if ($last && ($last->isTwo() || $last->isBomb())) {
            for ($i = 1; $i < $this->players; $i++) {
                $s = ($this->lastPlay['seat'] + $i) % $this->players;
                if (in_array($s, $this->passed, true) && ! in_array($s, $this->declined, true) && ! in_array($s, $this->freeChopSeats, true)
                    && $this->isActive($s) && $this->outOfTurnChops($s) !== []) {
                    return $s;
                }
            }
        }

        return $this->turn;
    }

    // Người đang được hỏi có chặt ngoài lượt không (không phải lượt thường).
    public function isInterrupt(int $seat): bool
    {
        return $this->actor() === $seat && $seat !== $this->turn;
    }

    /** @return Combo[] */
    public function outOfTurnChops(int $seat): array
    {
        $last = $this->lastCombo();
        if (! $last || $seat === $this->lastPlay['seat']) {
            return [];
        }

        return array_values(array_filter(MoveGenerator::all($this->hands[$seat]), fn (Combo $c) => Rules::canChopOutOfTurn($last, $c)));
    }

    /** @return Combo[] các bộ người này được đánh lúc này */
    public function legalMoves(int $seat): array
    {
        if ($this->actor() !== $seat) {
            return [];
        }

        return $this->isInterrupt($seat)
            ? $this->outOfTurnChops($seat)
            : MoveGenerator::legal($this->hands[$seat], $this->lastCombo(), $this->mustInclude);
    }

    // Ghế người thật trong phòng nhiều người có thể tự chặt ngoài lượt bằng bộ này ngay lúc này không.
    public function canFreeChop(int $seat, Combo $combo): bool
    {
        $last = $this->lastCombo();

        return $this->status === self::STATUS_PLAYING && $last !== null && in_array($seat, $this->freeChopSeats, true)
            && $seat !== $this->lastPlay['seat'] && $this->isActive($seat) && Rules::canChopOutOfTurn($last, $combo);
    }

    /** @return Combo[] các bộ ghế này đang tự chặt ngoài lượt được (chỉ ghế freeChopSeats, không phải lượt của mình) */
    public function freeChops(int $seat): array
    {
        if (! in_array($seat, $this->freeChopSeats, true) || $this->actor() === $seat || ! $this->lastPlay || ! $this->isActive($seat)) {
            return [];
        }

        return $this->outOfTurnChops($seat);
    }

    public function canPass(int $seat): bool
    {
        return $this->actor() === $seat && ($this->isInterrupt($seat) || $this->lastPlay !== null);
    }

    /** @param int[] $cards */
    public function play(int $seat, array $cards, bool $auto = false): Combo
    {
        $this->assertPlaying();

        $cards = array_values(array_unique(array_map('intval', $cards)));
        if ($cards === []) {
            throw new GameException('Bạn chưa chọn lá bài nào.');
        }
        if (array_diff($cards, $this->hands[$seat] ?? []) !== []) {
            throw new GameException('Lá bài không có trên tay bạn.');
        }

        $combo = Combo::detect($cards) ?? throw new GameException('Bộ bài không hợp lệ.');
        $actor = $this->actor();
        $last  = $this->lastCombo();
        $outOfTurn = false;

        if ($seat !== $actor) {
            // Ghế tự chặt ngoài lượt (phòng nhiều người): chỉ được đánh 4 đôi thông chặt bộ trên bàn
            if (! $this->canFreeChop($seat, $combo)) {
                throw new GameException($last && in_array($seat, $this->freeChopSeats, true) && $this->isActive($seat)
                    ? 'Chưa tới lượt của bạn — ngoài lượt chỉ được chặt bằng 4 đôi thông.' : 'Chưa tới lượt của bạn.');
            }
            $outOfTurn = true;
        } elseif ($seat !== $this->turn) {
            if (! Rules::canChopOutOfTurn($last, $combo)) {
                throw new GameException('Ngoài lượt chỉ được chặt bằng 4 đôi thông trở lên.');
            }
            $outOfTurn = true;
        } elseif ($last === null) {
            if ($this->mustInclude !== null && ! in_array($this->mustInclude, $combo->cards, true)) {
                throw new GameException('Ván đầu phải đánh bộ có lá ' . Card::code($this->mustInclude) . '.');
            }
        } elseif (! Rules::canBeat($last, $combo)) {
            throw new GameException('Bộ bài này không chặn được bộ trên bàn.');
        }

        $chop = $last !== null && Rules::isChop($last, $combo);
        if ($chop) {
            $this->chainValue += Rules::chopValue($last);
            $this->chopPending = ['chopper' => $seat, 'victim' => $this->lastPlay['seat'], 'units' => $this->chainValue];
        } else {
            $this->chainValue  = 0;
            $this->chopPending = null;
        }

        $this->hands[$seat]     = array_values(array_diff($this->hands[$seat], $combo->cards));
        $this->playedAny[$seat] = true;
        $this->mustInclude      = null;
        $this->lastPlay         = ['seat' => $seat, 'cards' => $combo->cards];
        $this->passed           = array_values(array_diff($this->passed, [$seat]));
        $this->declined         = [];

        $this->log[] = [
            'type' => 'play', 'seat' => $seat, 'cards' => $combo->cards, 'combo' => $combo->type, 'length' => $combo->length,
            'chop' => $chop, 'out_of_turn' => $outOfTurn, 'auto' => $auto, 'left' => count($this->hands[$seat]),
        ];

        if ($this->hands[$seat] === []) {
            $this->finishSeat($seat);
            if ($this->status !== self::STATUS_PLAYING) {
                return $combo;
            }
        }

        $this->advanceFrom($seat);

        return $combo;
    }

    public function pass(int $seat, bool $auto = false): void
    {
        $this->assertPlaying();

        if ($seat !== $this->actor()) {
            throw new GameException('Chưa tới lượt của bạn.');
        }

        if ($seat !== $this->turn) {
            $this->declined[] = $seat;
            $this->log[] = ['type' => 'decline_chop', 'seat' => $seat, 'auto' => $auto];

            return;
        }

        if ($this->lastPlay === null) {
            throw new GameException('Bạn đang được mở vòng, không được bỏ lượt.');
        }

        $this->passed[] = $seat;
        $this->log[] = ['type' => 'pass', 'seat' => $seat, 'auto' => $auto];
        $this->advanceFrom($seat);
    }

    // Hết giờ: đang được hỏi chặt ngoài lượt ⇒ bỏ qua; mở vòng ⇒ đánh lá nhỏ nhất; đang chặn ⇒ bỏ lượt.
    public function autoMove(int $seat): void
    {
        if ($this->isInterrupt($seat) || $this->lastPlay !== null) {
            $this->pass($seat, true);

            return;
        }

        $this->play($seat, [$this->mustInclude ?? min($this->hands[$seat])], true);
    }

    // Người chơi rời ván: xử thua (bét), ván kết thúc ngay.
    public function forfeit(int $seat): void
    {
        $this->assertPlaying();
        $this->log[] = ['type' => 'forfeit', 'seat' => $seat];
        $this->end($seat);
    }

    public function lastCombo(): ?Combo
    {
        return $this->lastPlay ? Combo::detect($this->lastPlay['cards']) : null;
    }

    public function isActive(int $seat): bool
    {
        return ! in_array($seat, $this->finished, true) && ! in_array($seat, $this->cong, true);
    }

    /** @return int[] */
    public function activeSeats(): array
    {
        return array_values(array_filter(range(0, $this->players - 1), fn (int $s) => $this->isActive($s)));
    }

    /** @return array<int, array> */
    public function flushLog(): array
    {
        $log       = $this->log;
        $this->log = [];

        return $log;
    }

    public function toArray(): array
    {
        return [
            'players'       => $this->players,
            'hands'         => $this->hands,
            'initial_hands' => $this->initialHands,
            'played_any'    => $this->playedAny,
            'finished'      => $this->finished,
            'cong'          => $this->cong,
            'turn'          => $this->turn,
            'last_play'     => $this->lastPlay,
            'passed'        => $this->passed,
            'declined'      => $this->declined,
            'must_include'  => $this->mustInclude,
            'first_match'   => $this->firstMatch,
            'chain_value'   => $this->chainValue,
            'chop_pending'  => $this->chopPending,
            'chops'         => $this->chops,
            'status'        => $this->status,
            'instant_win'   => $this->instantWin,
            'result'        => $this->result,
            'free_chop_seats' => $this->freeChopSeats,
        ];
    }

    public static function fromArray(array $a): self
    {
        $g = new self();
        $g->players      = (int) $a['players'];
        // JSON biến mảng key số liên tục thành list — ép lại về int key cho chắc
        $g->hands        = array_map(fn ($h) => array_map('intval', $h), array_values($a['hands']));
        $g->initialHands = array_map(fn ($h) => array_map('intval', $h), array_values($a['initial_hands']));
        $g->playedAny    = array_map('boolval', array_values($a['played_any']));
        $g->finished     = array_map('intval', $a['finished']);
        $g->cong         = array_map('intval', $a['cong']);
        $g->turn         = (int) $a['turn'];
        $g->lastPlay     = $a['last_play'];
        $g->passed       = array_map('intval', $a['passed']);
        $g->declined     = array_map('intval', $a['declined']);
        $g->mustInclude  = $a['must_include'];
        $g->firstMatch   = (bool) $a['first_match'];
        $g->chainValue   = (int) $a['chain_value'];
        $g->chopPending  = $a['chop_pending'];
        $g->chops        = $a['chops'];
        $g->status       = $a['status'];
        $g->instantWin   = $a['instant_win'];
        $g->result       = $a['result'];
        $g->freeChopSeats = array_map('intval', $a['free_chop_seats'] ?? []);

        return $g;
    }

    // =========================================================
    // PRIVATE
    // =========================================================

    private function assertPlaying(): void
    {
        if ($this->status !== self::STATUS_PLAYING) {
            throw new GameException('Ván đã kết thúc.');
        }
    }

    private function checkInstantWin(): void
    {
        $best = null;
        for ($i = 0; $i < $this->players; $i++) {
            $s    = ($this->turn + $i) % $this->players;
            $kind = Rules::instantWin($this->hands[$s]);
            if ($kind && ($best === null || Rules::INSTANT_PRIORITY[$kind] > Rules::INSTANT_PRIORITY[$best['kind']])) {
                $best = ['seat' => $s, 'kind' => $kind];
            }
        }

        if (! $best) {
            return;
        }

        $this->instantWin = $best;
        $this->status     = self::STATUS_FINISHED;
        $this->log[]      = ['type' => 'instant_win', 'seat' => $best['seat'], 'kind' => $best['kind'], 'cards' => $this->hands[$best['seat']]];

        $ranks  = [];
        $scores = [];
        foreach (range(0, $this->players - 1) as $s) {
            $ranks[$s]  = $s === $best['seat'] ? 1 : $this->players;
            $scores[$s] = $s === $best['seat'] ? ($this->players - 1) * Rules::UNIT_INSTANT : -Rules::UNIT_INSTANT;
        }

        $this->result = ['order' => [$best['seat']], 'ranks' => $ranks, 'scores' => $scores, 'chops' => [], 'penalties' => [], 'forfeit' => null];
        $this->log[]  = ['type' => 'game_end', 'ranks' => $ranks, 'scores' => $scores];
    }

    private function finishSeat(int $seat): void
    {
        $this->finished[] = $seat;
        $this->log[] = ['type' => 'finish', 'seat' => $seat, 'place' => count($this->finished)];

        if (count($this->finished) === 1) {
            foreach ($this->activeSeats() as $s) {
                if (! $this->playedAny[$s]) {
                    $this->cong[] = $s;
                    $this->log[]  = ['type' => 'cong', 'seat' => $s];
                }
            }
        }

        if (count($this->activeSeats()) <= 1) {
            $this->end();
        }
    }

    private function advanceFrom(int $seat): void
    {
        $owner = $this->lastPlay['seat'];

        for ($i = 1; $i <= $this->players; $i++) {
            $t = ($seat + $i) % $this->players;
            if ($t === $owner) {
                break;
            }
            if ($this->isActive($t) && ! in_array($t, $this->passed, true)) {
                $this->turn = $t;

                return;
            }
        }

        $this->roundOver();
    }

    private function roundOver(): void
    {
        $this->settleChop();

        $owner  = $this->lastPlay['seat'];
        $leader = $this->isActive($owner) ? $owner : $this->nextActive($owner);

        $this->lastPlay   = null;
        $this->passed     = [];
        $this->declined   = [];
        $this->chainValue = 0;
        $this->turn       = $leader;

        $this->log[] = ['type' => 'round_end', 'leader' => $leader, 'inherited' => $leader !== $owner];
    }

    private function nextActive(int $from): int
    {
        for ($i = 1; $i <= $this->players; $i++) {
            $t = ($from + $i) % $this->players;
            if ($this->isActive($t)) {
                return $t;
            }
        }

        return $from;
    }

    private function settleChop(): void
    {
        if ($this->chopPending) {
            $this->chops[]     = $this->chopPending;
            $this->log[]       = ['type' => 'chop_settled'] + $this->chopPending;
            $this->chopPending = null;
        }
    }

    // Chốt ván. $forfeiter: người bỏ cuộc — xếp bét (cùng hạng với người cóng), không tính thối.
    private function end(?int $forfeiter = null): void
    {
        $this->status = self::STATUS_FINISHED;
        $this->settleChop();

        $n     = $this->players;
        $order = $this->finished;
        $rest  = array_values(array_filter($this->activeSeats(), fn (int $s) => $s !== $forfeiter));

        // Bỏ cuộc giữa ván: những người còn lại xếp theo số lá còn ít hơn (bằng nhau thì người gần lượt hơn)
        usort($rest, fn (int $a, int $b) => [count($this->hands[$a]), ($a - $this->turn + $n) % $n] <=> [count($this->hands[$b]), ($b - $this->turn + $n) % $n]);
        array_push($order, ...$rest);

        $ranks = [];
        foreach ($order as $i => $s) {
            $ranks[$s] = $i + 1;
        }
        $losers = array_values(array_unique(array_merge($this->cong, $forfeiter !== null ? [$forfeiter] : [])));
        foreach ($losers as $s) {
            $ranks[$s] = $n;
        }
        ksort($ranks);

        $base   = Rules::BASE_BY_PLAYERS[$n];
        $scores = [];
        foreach ($ranks as $s => $r) {
            $scores[$s] = $base[$r - 1];
        }

        foreach ($this->chops as $c) {
            $scores[$c['chopper']] += $c['units'];
            $scores[$c['victim']]  -= $c['units'];
        }

        $penalties = [];
        $winner    = $order[0] ?? null;

        // Thối: người bét còn cầm bài trả người về ngay trên mình. Có người cóng thì người cóng mới là bét — người còn bài không bị thối.
        if ($losers === [] && count($order) >= 2) {
            $loser = $order[count($order) - 1];
            $thoi  = Rules::leftoverPenalty($this->hands[$loser]);
            if ($thoi['units'] > 0) {
                $payee = $order[count($order) - 2];
                $scores[$loser] -= $thoi['units'];
                $scores[$payee] += $thoi['units'];
                $penalties[] = ['kind' => 'thoi', 'seat' => $loser, 'to' => $payee, 'units' => $thoi['units'], 'items' => $thoi['items']];
            }
        }

        // Cóng: đền người về nhất phần cóng + thối toàn bộ hàng đang giữ
        foreach ($this->cong as $s) {
            $thoi  = Rules::leftoverPenalty($this->hands[$s]);
            $units = Rules::UNIT_CONG + $thoi['units'];
            if ($winner !== null) {
                $scores[$s]      -= $units;
                $scores[$winner] += $units;
            }
            $penalties[] = ['kind' => 'cong', 'seat' => $s, 'to' => $winner, 'units' => $units, 'items' => $thoi['items']];
        }

        $this->result = ['order' => $order, 'ranks' => $ranks, 'scores' => $scores, 'chops' => $this->chops, 'penalties' => $penalties, 'forfeit' => $forfeiter];
        $this->log[]  = ['type' => 'game_end', 'ranks' => $ranks, 'scores' => $scores];
    }

    /** @param int[] $deck @return int[] */
    private static function shuffle(array $deck): array
    {
        for ($i = count($deck) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$deck[$i], $deck[$j]] = [$deck[$j], $deck[$i]];
        }

        return $deck;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\TienLenRoomUpdated;
use App\Games\TienLen\Bot;
use App\Games\TienLen\Card;
use App\Games\TienLen\Combo;
use App\Games\TienLen\Game;
use App\Games\TienLen\GameException;
use App\Games\TienLen\Rules;
use App\Games\TienLen\StaleMatchException;
use App\Models\Customer;
use App\Models\GamePointTransaction;
use App\Models\TienLenMatch;
use App\Models\TienLenMatchPlayer;
use App\Models\TienLenMove;
use App\Models\TienLenRoom;
use App\Models\TienLenRoomSeat;
use App\Models\TienLenSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MINI GAME TIẾN LÊN MIỀN NAM — chạy 1 ván ở BE cho 2 chế độ:
 *  - Chơi với bot: khách ngồi ghế 0 + 1–3 bot (start()).
 *  - Phòng nhiều người: ghế là người thật hoặc bot do chủ phòng mời (createRoomMatch(), gọi từ TienLenRoomService).
 * Mỗi request của 1 người chạy nước của người đó rồi cho bot (và ghế đang được bot đánh thay) đánh tiếp tới khi tới lượt 1 người thật
 * hoặc hết ván; trả về chuỗi sự kiện (có event_no) để app chạy hiệu ứng. Người khác trong phòng nhận qua Reverb (TienLenRoomUpdated)
 * hoặc hỏi lại GET /rooms/{id}?after=event_no.
 *
 *  - Ván nối tiếp: cùng thành phần bàn, ván trước kết thúc trong SERIES_MINUTES ⇒ người về nhất đi trước, tự do.
 *  - Mỗi nước đi gửi kèm seq, lệch ⇒ 409 (bấm trùng / gửi nước cũ / người khác vừa đánh).
 *  - Hết giờ lượt: request kế tiếp của BẤT KỲ ai trong ván (hoặc lệnh tien-len:expire-idle mỗi phút) tự xử lý lượt đó.
 *    Hết giờ AUTO_AFTER_TIMEOUTS lần liền ⇒ bot đánh thay cho tới khi người đó gọi API lại.
 *  - Rời giữa ván (phòng): bot đánh nốt ghế đó, người rời 0 điểm. Ván với bot: bỏ ván = xử thua.
 *  - Điểm thưởng theo hạng + thưởng chặt (TienLenSetting), từng người riêng; cóng / bỏ ván / rời / đang bị bot đánh thay lúc hết ván = 0;
 *    áp trần điểm/ngày theo từng khách.
 */
class TienLenService
{
    public const HUMAN               = 0; // ghế của khách trong ván chơi với bot
    public const SERIES_MINUTES      = 120;
    public const GRACE_SECONDS       = 5;
    public const IDLE_MINUTES        = 30;
    public const AUTO_AFTER_TIMEOUTS = 2;

    public function __construct(private readonly GamePointService $points)
    {
    }

    // =========================================================
    // CHƠI VỚI BOT
    // =========================================================

    /** @return array{match: TienLenMatch, events: array} */
    public function start(Customer $customer, int $bots): array
    {
        $setting = $this->enabledSetting();
        if ($bots < 1 || $bots > 3) {
            throw new GameException('Chọn từ 1 đến 3 bot.');
        }

        return DB::transaction(function () use ($customer, $bots, $setting) {
            TienLenMatch::where('customer_id', $customer->id)->whereNull('room_id')->where('status', TienLenMatch::STATUS_PLAYING)
                ->lockForUpdate()->get()->each(fn (TienLenMatch $old) => $this->abandon($old));

            $seats = [self::seatEntry((string) $customer->id, $customer->fullname ?: 'Khách', false, null)];
            for ($i = 1; $i <= $bots; $i++) {
                $seats[] = self::seatEntry(null, 'Máy ' . $i, true, null);
            }

            $game = Game::deal(count($seats), $this->seriesLeader(
                TienLenMatch::where('customer_id', $customer->id)->whereNull('room_id'), $seats,
            ));

            return $this->createMatch($game, $seats, (string) $customer->id, null, $setting);
        });
    }

    public function current(Customer $customer): ?TienLenMatch
    {
        return TienLenMatch::where('customer_id', $customer->id)->whereNull('room_id')->where('status', TienLenMatch::STATUS_PLAYING)->latest()->first();
    }

    // =========================================================
    // PHÒNG NHIỀU NGƯỜI (gọi từ TienLenRoomService trong transaction đang khoá phòng)
    // =========================================================

    /**
     * @param array<int, TienLenRoomSeat> $roomSeats ghế đã có người/bot, theo thứ tự ghế phòng
     * @return array{match: TienLenMatch, events: array}
     */
    public function createRoomMatch(TienLenRoom $room, array $roomSeats): array
    {
        $setting = $this->enabledSetting();
        $seats   = [];
        foreach ($roomSeats as $rs) {
            // Tên bot theo số ghế trong phòng — trùng với tên hiện ở phòng chờ (TienLenRoomService::payload)
            $seats[] = $rs->is_bot
                ? self::seatEntry(null, 'Máy ' . ($rs->seat + 1), true, $rs->seat)
                : self::seatEntry((string) $rs->customer_id, $rs->customer?->fullname ?: 'Khách', false, $rs->seat);
        }

        $game = Game::deal(count($seats), $this->seriesLeader(TienLenMatch::where('room_id', $room->id), $seats));
        $game->freeChopSeats = array_keys(array_filter($seats, fn (array $s) => ! $s['is_bot']));

        return $this->createMatch($game, $seats, $room->host_customer_id, $room->id, $setting);
    }

    // Người rời phòng giữa ván: bot đánh nốt ghế đó (0 điểm). Không còn người thật nào ⇒ huỷ ván. Gọi trong transaction.
    public function leaveMatch(TienLenMatch $match, string $customerId): array
    {
        $match = TienLenMatch::whereKey($match->id)->lockForUpdate()->first();
        $seat  = $match?->seatOf($customerId);
        if (! $match || $match->status !== TienLenMatch::STATUS_PLAYING || $seat === null) {
            return [];
        }

        $seats = $match->seatList();
        $seats[$seat]['left'] = true;
        $seats[$seat]['auto'] = true;
        TienLenMatchPlayer::where('match_id', $match->id)->where('customer_id', $customerId)->update(['status' => TienLenMatchPlayer::STATUS_LEFT]);

        $game = Game::fromArray($match->state);
        $game->log[] = ['type' => 'left', 'seat' => $seat];

        $stillHere = array_filter($seats, fn (array $s) => ! $s['is_bot'] && ! $s['left']);
        if ($stillHere === []) {
            if ($game->status === Game::STATUS_PLAYING) {
                $game->forfeit($seat);
            }

            return $this->persist($match, $game, $seats, $game->flushLog(), TienLenSetting::current(), true);
        }

        return $this->advance($match, $game, $seats, TienLenSetting::current());
    }

    // =========================================================
    // CHUNG
    // =========================================================

    // Ván mà khách này là người chơi (ván với bot của mình, hoặc ván phòng mình có ghế).
    public function find(Customer $customer, string $id): TienLenMatch
    {
        return $this->participantQuery((string) $customer->id)->whereKey($id)->firstOrFail();
    }

    /**
     * Xem ván: xử lý lượt quá giờ (của bất kỳ ai) và đưa khách đang bị bot đánh thay quay lại tự chơi.
     *
     * @return array{match: TienLenMatch, events: array}
     */
    public function sync(Customer $customer, string $id): array
    {
        $match = $this->find($customer, $id);
        $seat  = $match->seatOf((string) $customer->id);
        $back  = $seat !== null && ($match->seatList()[$seat]['auto'] ?? false) && ! ($match->seatList()[$seat]['left'] ?? false);

        if ($match->status !== TienLenMatch::STATUS_PLAYING || (! $back && ! $this->deadlinePassed($match))) {
            return ['match' => $match, 'events' => []];
        }

        return $this->mutate($customer, $id, null, null);
    }

    /** @param int[] $cards @return array{match: TienLenMatch, events: array} */
    public function play(Customer $customer, string $id, array $cards, int $seq): array
    {
        return $this->mutate($customer, $id, $seq, fn (Game $g, int $seat) => $g->play($seat, $cards));
    }

    /** @return array{match: TienLenMatch, events: array} */
    public function pass(Customer $customer, string $id, int $seq): array
    {
        return $this->mutate($customer, $id, $seq, fn (Game $g, int $seat) => $g->pass($seat));
    }

    // Ván với bot: bỏ ván = xử thua. Ván phòng: dùng TienLenRoomService::leave (bot đánh thay).
    /** @return array{match: TienLenMatch, events: array} */
    public function forfeit(Customer $customer, string $id): array
    {
        return DB::transaction(function () use ($customer, $id) {
            $match = TienLenMatch::where('customer_id', $customer->id)->whereNull('room_id')->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($match->status !== TienLenMatch::STATUS_PLAYING) {
                throw new GameException('Ván đã kết thúc.');
            }

            return ['match' => $match, 'events' => $this->abandon($match)];
        });
    }

    /** @return Combo[] gợi ý: các bộ ghế này đánh được lúc này (kể cả tự chặt ngoài lượt), nhỏ trước */
    public function hints(TienLenMatch $match, int $seat = self::HUMAN): array
    {
        if ($match->status !== TienLenMatch::STATUS_PLAYING) {
            return [];
        }

        $game  = Game::fromArray($match->state);
        $moves = $game->legalMoves($seat) ?: $game->freeChops($seat);
        usort($moves, fn (Combo $a, Combo $b) => [$a->isBomb(), Card::rank($a->top), $a->count(), $a->top] <=> [$b->isBomb(), Card::rank($b->top), $b->count(), $b->top]);

        return array_slice($moves, 0, 20);
    }

    /** @return array<int, array> sự kiện có event_no > $after (tối đa 400) */
    public function eventsAfter(TienLenMatch $match, int $after): array
    {
        return TienLenMove::where('match_id', $match->id)->where('event_no', '>', $after)->orderBy('event_no')->limit(400)->get()
            ->map(function (TienLenMove $m) {
                $e = ($m->payload ?? []) + ['type' => $m->type];
                if ($m->seat !== null) {
                    $e['seat'] = $m->seat;
                }
                if ($m->cards !== null) {
                    $e['cards'] = $m->cards;
                }

                return self::eventPayload($e) + ['event_no' => $m->event_no];
            })->all();
    }

    // Ván với bot bỏ không quá IDLE_MINUTES ⇒ xử thua. Ván phòng: lượt quá giờ được xử lý ngay (người treo máy ⇒ bot đánh thay).
    public function expireIdle(int $minutes = self::IDLE_MINUTES): int
    {
        $count = 0;
        TienLenMatch::whereNull('room_id')->where('status', TienLenMatch::STATUS_PLAYING)->where('updated_at', '<', now()->subMinutes($minutes))->pluck('id')
            ->each(function (string $id) use (&$count) {
                DB::transaction(function () use ($id, &$count) {
                    $match = TienLenMatch::whereKey($id)->where('status', TienLenMatch::STATUS_PLAYING)->lockForUpdate()->first();
                    if ($match) {
                        $this->abandon($match);
                        $count++;
                    }
                });
            });

        return $count;
    }

    // Lượt đã quá giờ mà không ai gọi API ⇒ xử lý. Trả về số ván đã xử lý.
    public function tickOverdue(): int
    {
        $count = 0;
        TienLenMatch::where('status', TienLenMatch::STATUS_PLAYING)->whereNotNull('turn_deadline')
            ->where('turn_deadline', '<', now()->subSeconds(self::GRACE_SECONDS))->pluck('id')
            ->each(function (string $id) use (&$count) {
                DB::transaction(function () use ($id, &$count) {
                    $match = TienLenMatch::whereKey($id)->where('status', TienLenMatch::STATUS_PLAYING)->lockForUpdate()->first();
                    if ($match && $this->deadlinePassed($match)) {
                        $seats = $match->seatList();
                        $this->advance($match, Game::fromArray($match->state), $seats, TienLenSetting::current(), true);
                        $count++;
                    }
                });
            });

        return $count;
    }

    // =========================================================
    // PAYLOAD CHO APP
    // =========================================================

    // Trạng thái ván theo góc nhìn 1 người: chỉ có bài của chính họ. $viewer = null ⇒ người xem không ngồi trong ván.
    public static function statePayload(TienLenMatch $match, ?int $viewer = self::HUMAN): array
    {
        $game    = Game::fromArray($match->state);
        $seats   = $match->seatList();
        $playing = $match->status === TienLenMatch::STATUS_PLAYING && $game->status === Game::STATUS_PLAYING;
        $actor   = $playing ? $game->actor() : null;
        $last    = $game->lastCombo();
        $setting = TienLenSetting::current();
        $mine    = $viewer !== null && $playing && ! ($seats[$viewer]['left'] ?? false);

        $players = [];
        for ($s = 0; $s < $game->players; $s++) {
            $place = array_search($s, $game->finished, true);
            $players[] = [
                'seat'           => $s,
                'name'           => $seats[$s]['name'] ?? ('Máy ' . $s),
                'is_me'          => $s === $viewer,
                'is_bot'         => (bool) ($seats[$s]['is_bot'] ?? $s !== self::HUMAN),
                'is_auto'        => (bool) ($seats[$s]['auto'] ?? false), // người thật đang được bot đánh thay (treo máy / đã rời)
                'has_left'       => (bool) ($seats[$s]['left'] ?? false),
                'card_count'     => count($game->hands[$s]),
                'passed'         => in_array($s, $game->passed, true),
                'finished_place' => $place === false ? null : $place + 1,
                'is_cong'        => in_array($s, $game->cong, true),
            ];
        }

        return [
            'id'                   => $match->id,
            'mode'                 => $match->room_id ? 'room' : 'bot',
            'room_id'              => $match->room_id,
            'status'               => $match->status,
            'seq'                  => $match->seq,
            'event_count'          => $match->event_count,
            'player_count'         => $game->players,
            'my_seat'              => $viewer,
            'first_match'          => $game->firstMatch,
            'must_include'         => $game->mustInclude !== null ? Card::payload([$game->mustInclude])[0] : null,
            'turn_seat'            => $playing ? $game->turn : null,
            'actor_seat'           => $actor,
            'is_my_turn'           => $mine && $actor === $viewer,
            // Ván với bot: khách đã bỏ lượt nhưng được hỏi có chặt ngoài lượt bằng 4 đôi thông không (pass = không chặt)
            'interrupt'            => $mine && $game->isInterrupt($viewer),
            'can_pass'             => $mine && $game->canPass($viewer),
            // Phòng nhiều người: đang có thể tự chặt ngoài lượt bằng 4 đôi thông (gửi play bình thường, ai nhanh hơn người kế tiếp thì chặt được)
            'can_chop_out_of_turn' => $mine && $game->freeChops($viewer) !== [],
            'turn_seconds'         => $setting->turn_seconds,
            // Hạn lượt của người đang phải đi (người thật) — mọi người cùng thấy để hiện đồng hồ
            'turn_deadline'        => $playing ? $match->turn_deadline?->toIso8601String() : null,
            'last_play'            => $last ? [
                'seat'   => $game->lastPlay['seat'],
                'type'   => $last->type,
                'label'  => $last->label(),
                'length' => $last->length,
                'cards'  => Card::payload($last->cards),
            ] : null,
            'players'              => $players,
            'my_hand'              => $viewer !== null ? Card::payload($game->hands[$viewer]) : [],
            'result'               => $match->status === TienLenMatch::STATUS_PLAYING ? null : self::resultPayload($match, $game, $viewer),
            'created_at'           => $match->created_at?->toDateTimeString(),
            'finished_at'          => $match->finished_at?->toDateTimeString(),
        ];
    }

    public static function eventPayload(array $e): array
    {
        $out = $e;
        if (isset($out['cards'])) {
            $out['cards'] = Card::payload($out['cards']);
        }
        if ($e['type'] === 'play') {
            $out['label'] = Combo::detect($e['cards'])?->label();
        }
        if ($e['type'] === 'deal') {
            $out['must_include'] = ($e['must_include'] ?? null) !== null ? Card::payload([$e['must_include']])[0] : null;
        }
        if ($e['type'] === 'instant_win') {
            $out['label'] = Rules::INSTANT_LABELS[$e['kind']] ?? $e['kind'];
        }

        return $out;
    }

    public static function comboPayload(Combo $c): array
    {
        return ['type' => $c->type, 'label' => $c->label(), 'cards' => Card::payload($c->cards)];
    }

    public static function rankLabel(int $rank, int $players): string
    {
        return match (true) {
            $rank === 1       => 'Nhất',
            $rank >= $players => 'Bét',
            $rank === 2       => 'Nhì',
            default           => 'Ba',
        };
    }

    private static function resultPayload(TienLenMatch $match, Game $game, ?int $viewer): array
    {
        $r       = $game->result ?? ['ranks' => [], 'scores' => [], 'chops' => [], 'penalties' => [], 'forfeit' => null];
        $seats   = $match->seatList();
        $rewards = $r['rewards'] ?? [];
        $mine    = $viewer !== null ? ($rewards[$viewer] ?? $rewards[(string) $viewer] ?? ($match->room_id ? null : ($r['reward'] ?? null))) : null;
        $myRank  = $viewer !== null && isset($r['ranks'][$viewer]) ? (int) $r['ranks'][$viewer] : null;

        return [
            'my_rank'       => $myRank,
            'my_rank_label' => $myRank ? self::rankLabel($myRank, $game->players) : null,
            'reward_points' => (int) ($mine['total'] ?? ($match->room_id ? 0 : $match->reward_points)),
            'reward'        => $mine,
            'instant_win'   => $game->instantWin ? $game->instantWin + ['label' => Rules::INSTANT_LABELS[$game->instantWin['kind']]] : null,
            'forfeit_seat'  => $r['forfeit'],
            'ranking'       => collect($r['ranks'])->map(fn ($rank, $seat) => [
                'seat'          => (int) $seat,
                'name'          => $seats[$seat]['name'] ?? ('Máy ' . $seat),
                'is_bot'        => (bool) ($seats[$seat]['is_bot'] ?? false),
                'has_left'      => (bool) ($seats[$seat]['left'] ?? false),
                'rank'          => (int) $rank,
                'rank_label'    => self::rankLabel((int) $rank, $game->players),
                'score'         => $r['scores'][$seat] ?? 0,
                'is_cong'       => in_array((int) $seat, $game->cong, true),
                'reward_points' => isset($rewards[$seat]) ? (int) $rewards[$seat]['total'] : null,
            ])->sortBy('rank')->values(),
            'chops'         => $r['chops'],
            'penalties'     => array_map(fn (array $p) => $p + ['cards' => Card::payload(array_merge(...array_column($p['items'], 'cards') ?: [[]]))], $r['penalties']),
            // Hết ván mới lật bài mọi người
            'hands'         => array_map(fn (array $h) => Card::payload($h), $game->hands),
        ];
    }

    // =========================================================
    // PRIVATE
    // =========================================================

    private function enabledSetting(): TienLenSetting
    {
        $setting = TienLenSetting::current();
        if (! $setting->is_enabled) {
            throw new GameException('Mini game Tiến lên đang tạm đóng.');
        }

        return $setting;
    }

    private static function seatEntry(?string $customerId, string $name, bool $isBot, ?int $roomSeat): array
    {
        return ['customer_id' => $customerId, 'name' => $name, 'is_bot' => $isBot, 'room_seat' => $roomSeat, 'auto' => false, 'left' => false, 'timeouts' => 0];
    }

    private function participantQuery(string $customerId)
    {
        return TienLenMatch::where(fn ($q) => $q->where(fn ($s) => $s->whereNull('room_id')->where('customer_id', $customerId))
            ->orWhereIn('id', TienLenMatchPlayer::where('customer_id', $customerId)->select('match_id')));
    }

    /** @return array{match: TienLenMatch, events: array} */
    private function createMatch(Game $game, array $seats, string $ownerId, ?string $roomId, TienLenSetting $setting): array
    {
        $match = TienLenMatch::create([
            'customer_id'    => $ownerId,
            'room_id'        => $roomId,
            'player_count'   => count($seats),
            'seats'          => $seats,
            'status'         => TienLenMatch::STATUS_PLAYING,
            'state'          => $game->toArray(),
            'is_first_match' => $game->firstMatch,
        ]);

        foreach ($seats as $i => $s) {
            if (! $s['is_bot']) {
                TienLenMatchPlayer::create(['match_id' => $match->id, 'seat' => $i, 'customer_id' => $s['customer_id']]);
            }
        }

        return ['match' => $match, 'events' => $this->advance($match, $game, $seats, $setting)];
    }

    /** @return array{match: TienLenMatch, events: array} */
    private function mutate(Customer $customer, string $id, ?int $seq, ?\Closure $action): array
    {
        $result = DB::transaction(function () use ($customer, $id, $seq, $action) {
            $match = $this->participantQuery((string) $customer->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($match->status !== TienLenMatch::STATUS_PLAYING) {
                return $action ? ['error' => new GameException('Ván đã kết thúc.')] : ['match' => $match, 'events' => []];
            }
            if ($seq !== null && $seq !== $match->seq) {
                return ['error' => new StaleMatchException('Trạng thái ván đã thay đổi, vui lòng tải lại.'), 'match' => $match];
            }

            $setting = TienLenSetting::current();
            $game    = Game::fromArray($match->state);
            $seats   = $match->seatList();
            $seat    = $match->seatOf((string) $customer->id);

            // Người đang bị bot đánh thay vừa quay lại ⇒ tự chơi tiếp
            if ($seat !== null && $seats[$seat]['auto'] && ! $seats[$seat]['left']) {
                $seats[$seat]['auto']     = false;
                $seats[$seat]['timeouts'] = 0;
                $game->log[] = ['type' => 'back', 'seat' => $seat];
            }

            // Có người quá giờ: tự xử lý các lượt đã trôi qua rồi mới xét nước vừa gửi
            if ($this->deadlinePassed($match)) {
                $events = $this->advance($match, $game, $seats, $setting, true);

                return $action
                    ? ['error' => new StaleMatchException('Đã hết thời gian lượt, hệ thống đã tự xử lý.'), 'match' => $match, 'events' => $events]
                    : ['match' => $match, 'events' => $events];
            }

            if ($action) {
                if ($seat === null) {
                    return ['error' => new GameException('Bạn không ngồi trong ván này.')];
                }
                if ($seats[$seat]['left']) {
                    return ['error' => new GameException('Bạn đã rời ván này.')];
                }
                $action($game, $seat); // GameException ⇒ rollback, không có gì thay đổi
                $seats[$seat]['timeouts'] = 0;
            }

            return ['match' => $match, 'events' => $this->advance($match, $game, $seats, $setting)];
        });

        if (isset($result['error'])) {
            if ($result['error'] instanceof StaleMatchException && isset($result['match'])) {
                $result['error']->match  = $result['match']->fresh();
                $result['error']->events = $result['events'] ?? [];
            }
            throw $result['error'];
        }

        return $result;
    }

    private function deadlinePassed(TienLenMatch $match): bool
    {
        return $match->turn_deadline !== null && $match->turn_deadline->copy()->addSeconds(self::GRACE_SECONDS)->isPast();
    }

    private static function humanControlled(array $seat): bool
    {
        return ! $seat['is_bot'] && ! $seat['auto'] && ! $seat['left'];
    }

    // Cho bot (và ghế đang được bot đánh thay) đánh tới khi tới lượt 1 người thật / hết ván.
    // $replayTimeouts: có người đã quá giờ ⇒ xử lý các lượt người thật bị lỡ theo thời gian thực.
    private function advance(TienLenMatch $match, Game $game, array &$seats, TienLenSetting $setting, bool $replayTimeouts = false): array
    {
        $deadline = $match->turn_deadline?->copy();
        $guard    = 0;

        while ($game->status === Game::STATUS_PLAYING && $guard++ < 2000) {
            $actor = $game->actor();

            if (self::humanControlled($seats[$actor])) {
                if ($replayTimeouts && $deadline && $deadline->copy()->addSeconds(self::GRACE_SECONDS)->isPast()) {
                    $game->autoMove($actor);
                    if (++$seats[$actor]['timeouts'] >= self::AUTO_AFTER_TIMEOUTS && $match->room_id) {
                        $seats[$actor]['auto'] = true;
                        $game->log[] = ['type' => 'auto_on', 'seat' => $actor];
                    }
                    $deadline->addSeconds($setting->turn_seconds);
                    continue;
                }
                break;
            }

            $cards = Bot::choose($game, $actor);
            $cards === null && $game->canPass($actor) ? $game->pass($actor) : $game->play($actor, $cards ?? [min($game->hands[$actor])]);
        }

        return $this->persist($match, $game, $seats, $game->flushLog(), $setting);
    }

    private function persist(TienLenMatch $match, Game $game, array $seats, array $events, TienLenSetting $setting, bool $abandoned = false): array
    {
        $now      = now();
        $rows     = [];
        $payloads = [];
        foreach ($events as $i => $e) {
            $no     = $match->event_count + $i + 1;
            $rows[] = [
                'match_id'   => $match->id,
                'event_no'   => $no,
                'seat'       => $e['seat'] ?? null,
                'type'       => $e['type'],
                'cards'      => isset($e['cards']) ? json_encode($e['cards']) : null,
                'payload'    => json_encode(array_diff_key($e, ['type' => 1, 'seat' => 1, 'cards' => 1])),
                'created_at' => $now,
            ];
            $payloads[] = self::eventPayload($e) + ['event_no' => $no];
        }
        if ($rows) {
            TienLenMove::insert($rows);
        }

        $match->event_count += count($events);
        $match->seq++;
        $match->seats = $seats;

        if ($game->status === Game::STATUS_FINISHED) {
            $this->finish($match, $game, $seats, $setting, $abandoned);
        } else {
            $actor = $game->actor();
            $match->turn_deadline = $actor !== null && self::humanControlled($seats[$actor]) ? $now->copy()->addSeconds($setting->turn_seconds) : null;
        }

        $match->state = $game->toArray();
        $match->save();

        if ($match->room_id) {
            $this->notifyRoom($match->room_id, $match->status === TienLenMatch::STATUS_PLAYING ? 'match' : 'match_end');
        }

        return $payloads;
    }

    // Chốt hạng + điểm thưởng từng người thật (gọi trong transaction đang khoá ván).
    private function finish(TienLenMatch $match, Game $game, array $seats, TienLenSetting $setting, bool $abandoned): void
    {
        $r       = $game->result;
        $rewards = [];

        foreach ($seats as $i => $s) {
            if ($s['is_bot']) {
                continue;
            }

            $rank  = (int) $r['ranks'][$i];
            $zero  = $abandoned || $s['left'] || $s['auto'] || $r['forfeit'] === $i || in_array($i, $game->cong, true);
            $chops = count(array_filter($r['chops'], fn (array $c) => $c['chopper'] === $i));
            $base  = $zero ? 0 : $setting->pointsForRank($rank, $game->players);
            $bonus = $zero ? 0 : $chops * $setting->chop_bonus_points;
            $total = $base + $bonus;

            $capped = false;
            if ($total > 0 && $setting->daily_points_cap > 0) {
                $left = max(0, $setting->daily_points_cap - $this->points->earnedToday((string) $s['customer_id'], GamePointTransaction::SOURCE_TIEN_LEN));
                if ($total > $left) {
                    $total  = $left;
                    $capped = true;
                }
            }

            // Ván với bot: reference = id ván (1 người); ván phòng: id ván:ghế
            $this->points->earn((string) $s['customer_id'], $total, GamePointTransaction::SOURCE_TIEN_LEN,
                $match->room_id ? $match->id . ':' . $i : $match->id, 'Tiến lên: về ' . mb_strtolower(self::rankLabel($rank, $game->players)));

            $rewards[$i] = ['rank_points' => $base, 'chop_bonus' => $bonus, 'chops' => $chops, 'daily_cap_reached' => $capped, 'total' => $total];

            TienLenMatchPlayer::where('match_id', $match->id)->where('customer_id', $s['customer_id'])->update([
                'rank'          => $rank,
                'score'         => (int) ($r['scores'][$i] ?? 0),
                'reward_points' => $total,
                'status'        => $s['left'] ? TienLenMatchPlayer::STATUS_LEFT : ($s['auto'] ? TienLenMatchPlayer::STATUS_AUTO : TienLenMatchPlayer::STATUS_FINISHED),
            ]);
        }

        $game->result['rewards'] = $rewards;
        $game->result['reward']  = $rewards[self::HUMAN] ?? null;

        $solo = $match->room_id === null;
        $match->status        = $abandoned ? TienLenMatch::STATUS_ABANDONED : TienLenMatch::STATUS_FINISHED;
        $match->human_rank    = $solo ? (int) $r['ranks'][self::HUMAN] : null;
        $match->human_score   = $solo ? (int) ($r['scores'][self::HUMAN] ?? 0) : null;
        $match->reward_points = (int) array_sum(array_column($rewards, 'total'));
        $match->instant_win   = $game->instantWin['kind'] ?? null;
        $match->turn_deadline = null;
        $match->finished_at   = now();

        if (! $solo) {
            // Phòng quay về chờ ván sau: bỏ ghế người đã rời, mọi người bấm sẵn sàng lại
            $room = TienLenRoom::whereKey($match->room_id)->lockForUpdate()->first();
            if ($room && $room->current_match_id === $match->id) {
                $room->update(['status' => TienLenRoom::STATUS_WAITING, 'current_match_id' => null, 'last_match_id' => $match->id]);
                TienLenRoomSeat::where('room_id', $room->id)->where('is_bot', false)->update(['is_ready' => false]);
            }
        }
    }

    private function abandon(TienLenMatch $match): array
    {
        $game = Game::fromArray($match->state);
        if ($game->status === Game::STATUS_PLAYING) {
            $game->forfeit(self::HUMAN);
        }

        return $this->persist($match, $game, $match->seatList(), $game->flushLog(), TienLenSetting::current(), true);
    }

    // Người về nhất ván trước: cùng thành phần bàn (cùng người/bot theo đúng thứ tự ghế), kết thúc bình thường trong SERIES_MINUTES. null = ván đầu.
    private function seriesLeader($scope, array $seats): ?int
    {
        $prev = (clone $scope)->where('status', TienLenMatch::STATUS_FINISHED)
            ->where('finished_at', '>=', now()->subMinutes(self::SERIES_MINUTES))->latest('finished_at')->first();

        if (! $prev || $prev->player_count !== count($seats)) {
            return null;
        }

        $sig = fn (array $list) => array_map(fn (array $s) => $s['is_bot'] ? 'bot' : $s['customer_id'], $list);
        if ($sig($prev->seatList()) !== $sig($seats)) {
            return null;
        }

        $order = $prev->state['result']['order'] ?? [];

        return isset($order[0]) ? (int) $order[0] : null;
    }

    private function notifyRoom(string $roomId, string $kind): void
    {
        DB::afterCommit(function () use ($roomId, $kind) {
            try {
                event(new TienLenRoomUpdated($roomId, $kind));
            } catch (\Throwable $e) {
                // Reverb tắt / lỗi mạng không được làm hỏng ván — app vẫn hỏi lại được bằng GET /rooms/{id}?after=
                Log::debug('TienLen: broadcast thất bại', ['room_id' => $roomId, 'error' => $e->getMessage()]);
            }
        });
    }

    public function notify(string $roomId, string $kind): void
    {
        $this->notifyRoom($roomId, $kind);
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CaroBonus;
use App\Models\CaroInvite;
use App\Models\CaroMatch;
use App\Models\CaroPlayerStat;
use App\Models\CaroPrize;
use App\Models\CaroQueueEntry;
use App\Models\CaroReward;
use App\Models\CaroSetting;
use App\Models\Customer;
use App\Models\User;
use App\Support\CaroBoard;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Promotion\App\Models\Coupon;

/**
 * Mini game CARO TRỰC TUYẾN (chỉ người với người): ghép ngẫu nhiên 2 khách đang chờ, hoặc khách mời bạn vào đấu — mời đích danh theo SĐT
 * (bạn nhận push + WS) hoặc gửi link/mã cho ai cũng được. Chờ quá match_wait_seconds mà không có ai thì app gợi ý mời bạn.
 * Mọi nước đi do BE kiểm tra (đúng lượt, ô trống, hết giờ) và BE phân thắng thua. Tiến độ (pending_wins) chạy theo vòng mốc thưởng: mỗi
 * trận thắng được tính (xem countsForReward), mỗi lần chơi đủ chuỗi ngày và lần bạn được mời chơi trận đầu đều +1; chạm mốc thì nhận voucher
 * (mốc cố định hoặc bốc theo trọng số), chạm mốc cuối thì về 0. Voucher cá nhân, 365home chịu, nằm trong /coupons/mine.
 * Hết giờ lượt được xử lý lười (mỗi lần đọc trận) và bởi lệnh caro:sweep chạy mỗi phút.
 */
class CaroService
{
    // App hỏi hàng chờ mỗi 2-3 giây; quá lâu không hỏi coi như đã rời hàng
    private const QUEUE_ALIVE_SECONDS = 10;

    // Bù độ trễ mạng trước khi xử thua vì hết giờ
    private const TIMEOUT_GRACE_SECONDS = 3;

    // Lượt đầu cộng thêm thời gian để cả 2 bên kịp vào màn chơi
    private const FIRST_TURN_BONUS_SECONDS = 5;

    public function __construct(private readonly CaroRealtimeService $realtime) {}

    /**
     * Vào/giữ hàng chờ. Đang có trận dở thì trả trận đó; có người khác đang chờ thì ghép luôn.
     *
     * @return array{status: 'matched', match: CaroMatch}|array{status: 'waiting', waited_seconds: int, suggest_invite: bool}
     */
    public function seek(Customer $customer): array
    {
        $setting = $this->enabledSetting();
        $id      = (string) $customer->id;

        if ($match = $this->activeMatch($id)) {
            CaroQueueEntry::whereKey($id)->delete();

            return ['status' => 'matched', 'match' => $match];
        }

        // Khoá toàn cục để 2 khách vào hàng cùng lúc không bị ghép trùng / ghép chéo 2 trận
        $result = Cache::lock('caro:matchmaking', 5)->block(3, function () use ($id, $setting) {
            $entry = CaroQueueEntry::find($id);

            if ($entry?->match_id) {
                $entry->delete();
                $match = CaroMatch::find($entry->match_id);
                if ($match) {
                    return ['status' => 'matched', 'match' => $match, 'paired' => false];
                }
                $entry = null;
            }

            $opponent = CaroQueueEntry::where('customer_id', '!=', $id)
                ->whereNull('match_id')
                ->where('last_seen_at', '>=', now()->subSeconds(self::QUEUE_ALIVE_SECONDS))
                ->orderBy('created_at')
                ->first();

            if ($opponent) {
                $match = $this->createMatch($setting, $id, (string) $opponent->customer_id);
                $opponent->update(['match_id' => $match->id]);
                $entry?->delete();

                return ['status' => 'matched', 'match' => $match, 'paired' => true];
            }

            $entry ??= new CaroQueueEntry(['customer_id' => $id]);
            $entry->last_seen_at = now();
            $entry->save();

            $waited = (int) $entry->created_at->diffInSeconds(now());

            return [
                'status'         => 'waiting',
                'waited_seconds' => $waited,
                'suggest_invite' => $setting->invite_enabled && $waited >= $setting->match_wait_seconds,
            ];
        });

        if (($result['paired'] ?? false) === true) {
            $match = $result['match'];
            foreach ([$match->player_x_id, $match->player_o_id] as $playerId) {
                $this->realtime->push((string) $playerId, 'caro.matched', ['match' => $this->state($match, (string) $playerId)]);
            }
        }
        unset($result['paired']);

        return $result;
    }

    public function leaveQueue(Customer $customer): void
    {
        CaroQueueEntry::whereKey((string) $customer->id)->whereNull('match_id')->delete();
    }

    /**
     * Tạo lời mời đấu. Có SĐT thì mời đích danh (bạn nhận push + WS caro.invited), không có thì là lời mời mở — gửi link/mã cho ai cũng được.
     * Mỗi khách chỉ giữ 1 lời mời đang chờ: tạo lời mời mới thì lời mời cũ bị huỷ.
     */
    public function createInvite(Customer $inviter, ?string $phone): CaroInvite
    {
        $setting = $this->inviteSetting();
        $id      = (string) $inviter->id;

        if ($this->activeMatch($id)) {
            $this->fail('Bạn đang trong một trận khác.');
        }

        $invitee = null;
        if ($phone !== null && $phone !== '') {
            $invitee = Customer::whereIn('phone', array_unique([$phone, app(ZaloOtpService::class)->normalizePhone($phone)]))->first();

            if (! $invitee) {
                $this->fail('Không tìm thấy khách hàng với số điện thoại này. Bạn có thể gửi link mời thay vì số điện thoại.');
            }
            if ((string) $invitee->id === $id) {
                $this->fail('Không thể tự mời chính mình.');
            }
        }

        $invite = DB::transaction(function () use ($id, $invitee, $setting) {
            CaroInvite::where('inviter_id', $id)->where('status', CaroInvite::STATUS_PENDING)
                ->get()
                ->each(fn (CaroInvite $old) => $this->closeInvite($old, CaroInvite::STATUS_CANCELLED));

            do {
                $code = strtoupper(Str::random(6));
            } while (CaroInvite::where('code', $code)->exists());

            return CaroInvite::create([
                'code'       => $code,
                'inviter_id' => $id,
                'invitee_id' => $invitee?->id,
                'status'     => CaroInvite::STATUS_PENDING,
                'expires_at' => now()->addMinutes($setting->invite_expire_minutes),
            ]);
        });

        CaroQueueEntry::whereKey($id)->whereNull('match_id')->delete();

        if ($invitee) {
            $this->realtime->push((string) $invitee->id, 'caro.invited', ['invite' => $this->invitePayload($invite, (string) $invitee->id)]);

            try {
                app(NotificationFcmService::class)->sendToCustomer(
                    $invitee, 'Lời mời đấu Caro', self::maskName($inviter->fullname) . ' mời bạn một ván caro. Vào ngay!', 'caro_invite',
                    ['code' => $invite->code], '/games/caro/invites/' . $invite->code,
                );
            } catch (\Throwable $e) {
                Log::warning('Caro: gửi thông báo lời mời thất bại', ['invite' => $invite->code, 'error' => $e->getMessage()]);
            }
        }

        return $invite;
    }

    // Lời mời theo mã — người mời, người được mời đích danh, hoặc bất kỳ ai nếu là lời mời mở.
    public function findInvite(string $code, Customer $customer): CaroInvite
    {
        $invite = CaroInvite::where('code', strtoupper($code))->firstOrFail();
        $id     = (string) $customer->id;

        if ($invite->invitee_id && ! in_array($id, [(string) $invite->inviter_id, (string) $invite->invitee_id], true)) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(CaroInvite::class);
        }

        return $this->expireIfDue($invite);
    }

    // Nhận lời mời → tạo trận ngay giữa người mời và người nhận, cả 2 nhận WS caro.matched.
    public function acceptInvite(string $code, Customer $customer): CaroMatch
    {
        $setting = $this->inviteSetting();
        $id      = (string) $customer->id;
        $invite  = $this->findInvite($code, $customer);

        if ((string) $invite->inviter_id === $id) {
            $this->fail('Không thể tự nhận lời mời của chính mình.');
        }

        $match = Cache::lock('caro:matchmaking', 5)->block(3, fn () => DB::transaction(function () use ($invite, $id, $setting) {
            $invite = CaroInvite::whereKey($invite->id)->lockForUpdate()->first();

            if (! $invite->isOpen()) {
                $this->fail(match ($invite->status) {
                    CaroInvite::STATUS_ACCEPTED  => 'Lời mời đã được nhận.',
                    CaroInvite::STATUS_CANCELLED => 'Người mời đã huỷ lời mời.',
                    CaroInvite::STATUS_DECLINED  => 'Lời mời đã bị từ chối.',
                    default                      => 'Lời mời đã hết hạn.',
                });
            }
            if ($this->activeMatch($id)) {
                $this->fail('Bạn đang trong một trận khác.');
            }
            if ($this->activeMatch((string) $invite->inviter_id)) {
                $this->fail('Người mời đang trong một trận khác.');
            }

            $match = $this->createMatch($setting, (string) $invite->inviter_id, $id, 'invite');
            $invite->update(['status' => CaroInvite::STATUS_ACCEPTED, 'match_id' => $match->id, 'invitee_id' => $invite->invitee_id ?? $id]);
            CaroQueueEntry::whereIn('customer_id', [$id, (string) $invite->inviter_id])->delete();

            return $match;
        }));

        foreach ([$match->player_x_id, $match->player_o_id] as $playerId) {
            $this->realtime->push((string) $playerId, 'caro.matched', ['match' => $this->state($match, (string) $playerId)]);
        }

        return $match;
    }

    // Người được mời đích danh từ chối → người mời nhận WS caro.invite_updated.
    public function declineInvite(string $code, Customer $customer): CaroInvite
    {
        $invite = $this->findInvite($code, $customer);

        if ((string) $invite->invitee_id !== (string) $customer->id) {
            $this->fail('Chỉ người được mời mới từ chối được lời mời này.');
        }

        return $this->closeOpenInvite($invite, CaroInvite::STATUS_DECLINED);
    }

    // Người mời huỷ → người được mời đích danh (nếu có) nhận WS caro.invite_updated.
    public function cancelInvite(string $code, Customer $customer): CaroInvite
    {
        $invite = $this->findInvite($code, $customer);

        if ((string) $invite->inviter_id !== (string) $customer->id) {
            $this->fail('Chỉ người mời mới huỷ được lời mời.');
        }

        return $this->closeOpenInvite($invite, CaroInvite::STATUS_CANCELLED);
    }

    /** @return array{incoming: \Illuminate\Support\Collection<int, CaroInvite>, outgoing: ?CaroInvite} */
    public function invitesOf(Customer $customer): array
    {
        $id = (string) $customer->id;

        return [
            'incoming' => CaroInvite::where('invitee_id', $id)->where('status', CaroInvite::STATUS_PENDING)
                ->where('expires_at', '>', now())->latest()->get(),
            'outgoing' => CaroInvite::where('inviter_id', $id)->where('status', CaroInvite::STATUS_PENDING)
                ->where('expires_at', '>', now())->latest()->first(),
        ];
    }

    public function invitePayload(CaroInvite $invite, string $viewerId): array
    {
        $setting = CaroSetting::current();
        $url     = rtrim($setting->invite_share_url_base, '/') . '/' . $invite->code;
        $mine    = (string) $invite->inviter_id === $viewerId;
        $match   = $invite->match_id ? CaroMatch::find($invite->match_id) : null;
        $names   = Customer::whereIn('id', array_filter([$invite->inviter_id, $invite->invitee_id]))->pluck('fullname', 'id');

        return [
            'code'         => $invite->code,
            'status'       => $invite->isOpen() || $invite->status !== CaroInvite::STATUS_PENDING ? $invite->status : CaroInvite::STATUS_EXPIRED,
            'is_mine'      => $mine,
            'is_targeted'  => $invite->invitee_id !== null,
            'inviter'      => ['name' => self::maskName($names[$invite->inviter_id] ?? null)],
            'invitee'      => $invite->invitee_id ? ['name' => self::maskName($names[$invite->invitee_id] ?? null)] : null,
            'expires_at'   => $invite->expires_at->toIso8601String(),
            'seconds_left' => max(0, (int) now()->diffInSeconds($invite->expires_at, false)),
            'share_url'    => $mine ? $url : null,
            'share_text'   => $mine ? "Vào đấu caro với mình trên app 365home nhé! Mã mời {$invite->code}: {$url}" : null,
            'match'        => $match && $match->sideOf($viewerId) ? $this->state($match, $viewerId) : null,
        ];
    }

    // Đọc trận của khách — tiện thể xử thua nếu lượt hiện tại đã hết giờ.
    public function find(string $matchId, Customer $customer): CaroMatch
    {
        $match = CaroMatch::whereKey($matchId)
            ->where(fn ($q) => $q->where('player_x_id', $customer->id)->orWhere('player_o_id', $customer->id))
            ->firstOrFail();

        if ($this->isTimedOut($match)) {
            $match = $this->resolveTimeout($match->id) ?? $match->refresh();
        }

        return $match;
    }

    public function move(string $matchId, Customer $customer, int $x, int $y): CaroMatch
    {
        $match = DB::transaction(function () use ($matchId, $customer, $x, $y) {
            $match = $this->lockedMatchOf($matchId, $customer);
            $side  = $match->sideOf((string) $customer->id);

            if ($this->isTimedOut($match)) {
                $this->finish($match, $match->turn() === 'x' ? 'o' : 'x', 'timeout');

                return $match;
            }

            if ($match->turn() !== $side) {
                $this->fail('Chưa tới lượt của bạn.');
            }

            $board = CaroBoard::fromMoves($match->board_size, $match->moves);

            if (! $board->inBounds($x, $y) || $board->at($x, $y) !== null) {
                $this->fail('Ô này không đánh được.');
            }

            $this->play($match, $board, $x, $y);

            return $match;
        });

        $this->pushToOpponent($match, (string) $customer->id);

        return $match;
    }

    public function resign(string $matchId, Customer $customer): CaroMatch
    {
        $match = DB::transaction(function () use ($matchId, $customer) {
            $match = $this->lockedMatchOf($matchId, $customer);
            $this->finish($match, $match->sideOf((string) $customer->id) === 'x' ? 'o' : 'x', 'resign');

            return $match;
        });

        $this->pushToOpponent($match, (string) $customer->id);

        return $match;
    }

    // Xử thua bên đang tới lượt nếu đã quá giờ. Trả null nếu trận không còn hết giờ (vd vừa có nước đi).
    public function resolveTimeout(string $matchId): ?CaroMatch
    {
        $match = DB::transaction(function () use ($matchId) {
            $match = CaroMatch::whereKey($matchId)->lockForUpdate()->first();

            if (! $match || ! $this->isTimedOut($match)) {
                return null;
            }

            $this->finish($match, $match->turn() === 'x' ? 'o' : 'x', 'timeout');

            return $match;
        });

        if ($match) {
            $this->pushToOpponent($match, null);
        }

        return $match;
    }

    // Lệnh caro:sweep: xử thua các trận hết giờ mà không ai mở lại, dọn hàng chờ đã rời, đánh dấu lời mời hết hạn.
    // Trả về [số trận hết giờ, số dòng hàng chờ đã dọn].
    public function sweep(): array
    {
        $timedOut = 0;
        CaroMatch::where('status', CaroMatch::STATUS_PLAYING)
            ->where('turn_deadline_at', '<', now()->subSeconds(self::TIMEOUT_GRACE_SECONDS))
            ->pluck('id')
            ->each(function (string $id) use (&$timedOut) {
                if ($this->resolveTimeout($id)) {
                    $timedOut++;
                }
            });

        $purged = CaroQueueEntry::where('last_seen_at', '<', now()->subMinutes(5))->delete();

        CaroInvite::where('status', CaroInvite::STATUS_PENDING)->where('expires_at', '<=', now())->update(['status' => CaroInvite::STATUS_EXPIRED]);

        return [$timedOut, $purged];
    }

    public function activeMatch(string $customerId): ?CaroMatch
    {
        return CaroMatch::where('status', CaroMatch::STATUS_PLAYING)
            ->where(fn ($q) => $q->where('player_x_id', $customerId)->orWhere('player_o_id', $customerId))
            ->latest()
            ->first();
    }

    /** @return array{waiting: int, playing: int} */
    public function onlineCount(): array
    {
        $playing = CaroMatch::where('status', CaroMatch::STATUS_PLAYING)
            ->where('updated_at', '>=', now()->subMinutes(2))->count();

        return [
            'waiting' => CaroQueueEntry::whereNull('match_id')->where('last_seen_at', '>=', now()->subSeconds(self::QUEUE_ALIVE_SECONDS))->count(),
            'playing' => $playing * 2,
        ];
    }

    public function progress(string $customerId): array
    {
        $setting    = CaroSetting::current();
        $stat       = CaroPlayerStat::find($customerId);
        $cycle      = $setting->cycleLength();
        $pending    = min((int) $stat?->pending_wins, $cycle);
        $labels     = CaroPrize::pluck('label', 'id');
        $milestones = array_map(fn (array $m) => [
            'wins'    => $m['wins'],
            'mode'    => $m['mode'],
            // Mốc ngẫu nhiên không lộ giải để giữ bất ngờ
            'label'   => $m['mode'] === 'fixed' ? ($labels[$m['prize_id']] ?? 'Voucher bí ẩn') : 'Voucher bí ẩn',
            'reached' => $pending >= $m['wins'],
        ], $setting->sortedMilestones());
        $next       = collect($milestones)->first(fn (array $m) => $m['wins'] > $pending);

        // Chuỗi đứt nếu lần chơi gần nhất trước hôm qua
        $lastPlayed = $stat?->last_played_on;
        $streak     = $lastPlayed && $lastPlayed->gte(today()->subDay()) ? (int) $stat->streak_days : 0;

        return [
            'wins'           => (int) $stat?->wins,
            'losses'         => (int) $stat?->losses,
            'draws'          => (int) $stat?->draws,
            'pending_wins'   => $pending,
            'cycle_wins'     => $cycle,
            'milestones'     => $milestones,
            'next_milestone' => $next ? [...$next, 'remaining' => $next['wins'] - $pending] : null,
            'today'          => [
                'counted_wins' => CaroMatch::where('winner_customer_id', $customerId)->where('counted_for_reward', true)
                    ->where('finished_at', '>=', now()->startOfDay())->count(),
                'cap'          => $setting->daily_counted_wins_cap,
            ],
            'streak'         => [
                'days'         => $streak,
                'target'       => $setting->streak_days ?: null,
                'played_today' => (bool) $lastPlayed?->isToday(),
            ],
            'rewards_count'  => CaroReward::where('customer_id', $customerId)->count(),
        ];
    }

    // Trạng thái trận nhìn từ phía 1 khách (quân của mình, tới lượt mình chưa, kết quả, voucher nhận được ở trận này).
    public function state(CaroMatch $match, string $customerId): array
    {
        $side       = $match->sideOf($customerId);
        $opponentId = $side === 'x' ? $match->player_o_id : $match->player_x_id;
        $finished   = $match->status === CaroMatch::STATUS_FINISHED;
        $rewards    = $finished ? CaroReward::where('match_id', $match->id)->where('customer_id', $customerId)->with('coupon')->get() : collect();

        return [
            'id'                => $match->id,
            'status'            => $match->status,
            'board_size'        => $match->board_size,
            'win_length'        => $match->win_length,
            'block_two_ends'    => $match->block_two_ends,
            'turn_seconds'      => $match->turn_seconds,
            'you'               => $side,
            'source'            => $match->source,
            'opponent'          => ['name' => self::maskName(Customer::find($opponentId)?->fullname)],
            'moves'             => $match->moves,
            'last_move'         => Arr::last($match->moves),
            'turn'              => $finished ? null : $match->turn(),
            'your_turn'         => ! $finished && $match->turn() === $side,
            'turn_deadline_at'  => $finished ? null : $match->turn_deadline_at?->toIso8601String(),
            'turn_seconds_left' => $finished ? null : max(0, (int) now()->diffInSeconds($match->turn_deadline_at, false)),
            'winner'            => $match->winner,
            'result'            => ! $finished ? null : match ($match->winner) {
                'draw'  => 'draw',
                $side   => 'win',
                default => 'lose',
            },
            'end_reason'        => $match->end_reason,
            'winning_line'      => $match->winning_line,
            'counted_for_reward' => $finished && $match->winner === $side ? $match->counted_for_reward : null,
            // Voucher nhận được nhờ trận này (có thể > 1 nếu trận thắng + trận thưởng chạm 2 mốc liền nhau)
            'rewards'           => $rewards->map(fn (CaroReward $r) => [
                'label'          => $r->prize_label,
                'milestone_wins' => $r->milestone_wins,
                'coupon'         => ScratchCardService::couponPayload($r->coupon),
            ])->values()->all(),
            // Trận thưởng nhận được nhờ trận này: streak (chơi chuỗi ngày) | invite (mời bạn mới)
            'bonuses'           => $finished ? CaroBonus::where('match_id', $match->id)->where('customer_id', $customerId)->pluck('type')->all() : [],
            'progress'          => $finished ? $this->progress($customerId) : null,
        ];
    }

    // =========================================================
    // PRIVATE
    // =========================================================

    private function enabledSetting(): CaroSetting
    {
        $setting = CaroSetting::current();

        if (! $setting->is_enabled) {
            $this->fail('Mini game caro đang tạm đóng.');
        }

        return $setting;
    }

    private function createMatch(CaroSetting $setting, string $playerA, string $playerB, string $source = 'queue'): CaroMatch
    {
        // Bốc ngẫu nhiên ai cầm X (đi trước)
        [$x, $o] = random_int(0, 1) === 0 ? [$playerA, $playerB] : [$playerB, $playerA];

        return CaroMatch::create([
            'player_x_id'      => $x,
            'player_o_id'      => $o,
            'source'           => $source,
            'board_size'       => $setting->board_size,
            'win_length'       => $setting->win_length,
            'block_two_ends'   => $setting->block_two_ends,
            'turn_seconds'     => $setting->turn_seconds,
            'moves'            => [],
            'status'           => CaroMatch::STATUS_PLAYING,
            'turn_deadline_at' => now()->addSeconds($setting->turn_seconds + self::FIRST_TURN_BONUS_SECONDS),
        ]);
    }

    private function inviteSetting(): CaroSetting
    {
        $setting = $this->enabledSetting();

        if (! $setting->invite_enabled) {
            $this->fail('Tính năng mời bạn đang tắt.');
        }

        return $setting;
    }

    // Lời mời quá hạn mà chưa ai đánh dấu thì đánh dấu luôn khi đọc
    private function expireIfDue(CaroInvite $invite): CaroInvite
    {
        if ($invite->status === CaroInvite::STATUS_PENDING && ! $invite->isOpen()) {
            $invite->update(['status' => CaroInvite::STATUS_EXPIRED]);
        }

        return $invite;
    }

    private function closeOpenInvite(CaroInvite $invite, string $status): CaroInvite
    {
        DB::transaction(function () use (&$invite, $status) {
            $invite = CaroInvite::whereKey($invite->id)->lockForUpdate()->first();

            if (! $invite->isOpen()) {
                $this->fail('Lời mời không còn hiệu lực.');
            }

            $this->closeInvite($invite, $status);
        });

        return $invite;
    }

    // Đóng lời mời và báo cho bên còn lại (người mời khi bị từ chối, người được mời đích danh khi bị huỷ)
    private function closeInvite(CaroInvite $invite, string $status): void
    {
        $invite->update(['status' => $status]);

        $notify = $status === CaroInvite::STATUS_DECLINED ? $invite->inviter_id : $invite->invitee_id;
        if ($notify) {
            $this->realtime->push((string) $notify, 'caro.invite_updated', ['invite' => $this->invitePayload($invite, (string) $notify)]);
        }
    }

    private function lockedMatchOf(string $matchId, Customer $customer): CaroMatch
    {
        $match = CaroMatch::whereKey($matchId)
            ->where(fn ($q) => $q->where('player_x_id', $customer->id)->orWhere('player_o_id', $customer->id))
            ->lockForUpdate()
            ->firstOrFail();

        if ($match->status !== CaroMatch::STATUS_PLAYING) {
            $this->fail('Trận đấu đã kết thúc.');
        }

        return $match;
    }

    // Đặt 1 quân cho bên đang tới lượt; trả true nếu trận kết thúc sau nước này.
    private function play(CaroMatch $match, CaroBoard $board, int $x, int $y): bool
    {
        $side = $match->turn();
        $board->place($x, $y, $side);
        $match->moves = [...$match->moves, [$x, $y]];

        if ($line = $board->winningLine($x, $y, $match->win_length, $match->block_two_ends)) {
            $this->finish($match, $side, 'line', $line);

            return true;
        }

        if ($board->isFull()) {
            $this->finish($match, 'draw', 'draw');

            return true;
        }

        $match->turn_deadline_at = now()->addSeconds($match->turn_seconds);
        $match->save();

        return false;
    }

    private function isTimedOut(CaroMatch $match): bool
    {
        return $match->status === CaroMatch::STATUS_PLAYING
            && $match->turn_deadline_at
            && $match->turn_deadline_at->lt(now()->subSeconds(self::TIMEOUT_GRACE_SECONDS));
    }

    // Chốt kết quả, cập nhật thành tích và trao voucher nếu đủ trận. Gọi trong transaction đang khoá trận.
    private function finish(CaroMatch $match, string $winner, string $reason, ?array $line = null): void
    {
        $setting  = CaroSetting::current();
        $winnerId = $winner === 'draw' ? null : $match->customerOf($winner);

        $match->fill([
            'status'             => CaroMatch::STATUS_FINISHED,
            'winner'             => $winner,
            'end_reason'         => $reason,
            'winning_line'       => $line,
            'winner_customer_id' => $winnerId,
            'turn_deadline_at'   => null,
            'finished_at'        => now(),
        ]);
        $match->counted_for_reward = $winnerId !== null && $this->countsForReward($match, $winnerId, $setting);
        $match->save();

        foreach (array_filter([$match->player_x_id, $match->player_o_id]) as $playerId) {
            $stat = CaroPlayerStat::whereKey($playerId)->lockForUpdate()->first() ?? CaroPlayerStat::create(['customer_id' => $playerId]);
            $stat->increment(match (true) {
                $winner === 'draw'      => 'draws',
                $playerId === $winnerId => 'wins',
                default                 => 'losses',
            });
        }

        if ($match->counted_for_reward) {
            $this->addProgress($winnerId, $match, $setting);
        }

        if ($this->isRealGame($match, $setting)) {
            foreach ([$match->player_x_id, $match->player_o_id] as $playerId) {
                $this->touchStreak((string) $playerId, $match, $setting);
            }
            $this->inviteBonus($match, $setting);
        }
    }

    // Trận "chơi thật" (không phải đầu hàng / bỏ ngang ngay từ đầu) mới được tính chuỗi ngày và thưởng mời bạn
    private function isRealGame(CaroMatch $match, CaroSetting $setting): bool
    {
        return $match->end_reason === 'line' || count($match->moves) >= $setting->min_moves_to_count;
    }

    // Ghi ngày chơi; chơi đủ streak_days ngày liên tiếp (và mỗi bội số tiếp theo) thì +1 trận thưởng.
    private function touchStreak(string $customerId, CaroMatch $match, CaroSetting $setting): void
    {
        $stat = CaroPlayerStat::whereKey($customerId)->lockForUpdate()->first();
        $last = $stat->last_played_on;

        if ($last?->isToday()) {
            return;
        }

        $streak = $last?->isYesterday() ? $stat->streak_days + 1 : 1;
        $stat->update(['streak_days' => $streak, 'last_played_on' => today()]);

        if ($setting->streak_days > 0 && $streak % $setting->streak_days === 0) {
            CaroBonus::create(['customer_id' => $customerId, 'type' => CaroBonus::TYPE_STREAK, 'match_id' => $match->id]);
            $this->addProgress($customerId, $match, $setting);
        }
    }

    // Bạn được mời (chưa từng chơi caro) chơi xong trận đầu với người mời → cả 2 +1 trận thưởng; người mời giới hạn số lần / ngày.
    private function inviteBonus(CaroMatch $match, CaroSetting $setting): void
    {
        if (! $setting->invite_bonus_enabled || $match->source !== 'invite') {
            return;
        }

        $invite = CaroInvite::where('match_id', $match->id)->first();
        if (! $invite?->invitee_id) {
            return;
        }

        $inviteeId = (string) $invite->invitee_id;
        $inviterId = (string) $invite->inviter_id;

        $playedBefore = CaroMatch::where('status', CaroMatch::STATUS_FINISHED)->whereKeyNot($match->id)
            ->where(fn ($q) => $q->where('player_x_id', $inviteeId)->orWhere('player_o_id', $inviteeId))->exists();

        if ($playedBefore || CaroBonus::where('customer_id', $inviteeId)->where('type', CaroBonus::TYPE_INVITE)->exists()) {
            return;
        }

        CaroBonus::create(['customer_id' => $inviteeId, 'type' => CaroBonus::TYPE_INVITE, 'match_id' => $match->id]);
        $this->addProgress($inviteeId, $match, $setting);

        $inviterToday = CaroBonus::where('customer_id', $inviterId)->where('type', CaroBonus::TYPE_INVITE)
            ->where('created_at', '>=', now()->startOfDay())->count();

        if ($setting->invite_bonus_daily_cap === null || $inviterToday < $setting->invite_bonus_daily_cap) {
            CaroBonus::create(['customer_id' => $inviterId, 'type' => CaroBonus::TYPE_INVITE, 'match_id' => $match->id]);
            $this->addProgress($inviterId, $match, $setting);
        }
    }

    // Thắng có được tính vào tiến độ nhận voucher không — chặn trận mời (nếu admin tắt), thông đồng đầu hàng sớm và cày 1 đối thủ.
    private function countsForReward(CaroMatch $match, string $winnerId, CaroSetting $setting): bool
    {
        if ($match->source === 'invite' && ! $setting->invite_wins_count) {
            return false;
        }

        if ($match->end_reason !== 'line' && count($match->moves) < $setting->min_moves_to_count) {
            return false;
        }

        $todayWins = CaroMatch::where('winner_customer_id', $winnerId)->where('counted_for_reward', true)
            ->where('finished_at', '>=', now()->startOfDay());

        if ($setting->daily_counted_wins_cap !== null && (clone $todayWins)->count() >= $setting->daily_counted_wins_cap) {
            return false;
        }

        if ($setting->same_opponent_daily_cap !== null) {
            $loserId = $winnerId === $match->player_x_id ? $match->player_o_id : $match->player_x_id;
            $vsSame  = (clone $todayWins)->where(fn ($q) => $q->where('player_x_id', $loserId)->orWhere('player_o_id', $loserId))->count();

            if ($vsSame >= $setting->same_opponent_daily_cap) {
                return false;
            }
        }

        return true;
    }

    // +1 tiến độ trong vòng mốc; chạm mốc thì trao voucher, chạm mốc cuối thì tiến độ về 0.
    private function addProgress(string $customerId, CaroMatch $match, CaroSetting $setting): void
    {
        $stat    = CaroPlayerStat::whereKey($customerId)->lockForUpdate()->first();
        $pending = $stat->pending_wins + 1;

        foreach ($setting->sortedMilestones() as $milestone) {
            if ($milestone['wins'] === $pending) {
                $this->awardMilestone($customerId, $match, $milestone);
            }
        }

        $stat->update(['pending_wins' => $pending >= $setting->cycleLength() ? 0 : $pending]);
    }

    /** @param array{wins: int, mode: string, prize_id: ?int} $milestone */
    private function awardMilestone(string $customerId, CaroMatch $match, array $milestone): void
    {
        $available = $this->availablePrizes();
        // Mốc cố định mà giải đó đã tắt / hết lượt thì bốc ngẫu nhiên thay để khách vẫn có quà
        $prize     = ($milestone['mode'] === 'fixed' ? $available->firstWhere('id', $milestone['prize_id']) : null) ?? $this->drawPrize($available);

        if (! $prize) {
            Log::warning('Caro: khách đạt mốc nhưng chưa có giải nào đang bật', ['customer_id' => $customerId, 'match_id' => $match->id]);

            return;
        }

        $customer = Customer::find($customerId);
        $coupon   = $this->createCoupon($customer, $prize);

        $prize->increment('awarded_count');

        CaroReward::create([
            'customer_id'    => $customerId,
            'match_id'       => $match->id,
            'prize_id'       => $prize->id,
            'prize_label'    => $prize->label,
            'coupon_id'      => $coupon->id,
            'milestone_wins' => $milestone['wins'],
        ]);

        try {
            app(NotificationFcmService::class)->sendToCustomer(
                $customer, 'Bạn nhận được voucher Caro',
                "Đạt mốc {$milestone['wins']} trận: {$prize->label}! Dùng trước {$coupon->end_at->format('d/m')}, áp chung được với mã khác không độc quyền.",
                'caro_reward', [], '/games/caro',
            );
        } catch (\Throwable $e) {
            Log::warning('Caro: gửi thông báo thất bại', ['match_id' => $match->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Nhắc khách các voucher caro chưa dùng sắp hết hạn (lệnh caro:remind-expiring, chạy mỗi giờ) — 1 thông báo / khách, mỗi voucher nhắc 1 lần.
     * Trả về số khách đã nhắc.
     */
    public function remindExpiring(): int
    {
        $setting = CaroSetting::current();
        if (! $setting->is_enabled || ! $setting->reminder_enabled) {
            return 0;
        }

        $rewards = CaroReward::whereNull('expiry_reminded_at')
            ->whereHas('coupon', fn ($q) => $q->where('is_active', true)->where('used_count', 0)
                ->where('end_at', '>', now())->where('end_at', '<=', now()->addHours($setting->reminder_hours_before)))
            ->with(['coupon', 'customer'])
            ->get();

        foreach ($rewards->groupBy('customer_id') as $items) {
            $customer = $items->first()->customer;
            $soonest  = $items->min(fn (CaroReward $r) => $r->coupon->end_at);
            $total    = $items->sum(fn (CaroReward $r) => $r->coupon->type === 'fixed' ? (float) $r->coupon->value : 0);
            $body     = $items->count() === 1
                ? "Mã {$items->first()->prize_label} của bạn hết hạn lúc {$soonest->format('H:i d/m')}. Đặt phòng ngay để không bỏ lỡ!"
                : "Bạn có {$items->count()} mã Caro" . ($total > 0 ? ' (tổng ' . number_format($total, 0, ',', '.') . 'đ)' : '')
                    . " sắp hết hạn, sớm nhất lúc {$soonest->format('H:i d/m')}. Đặt phòng ngay để dùng!";

            try {
                if ($customer) {
                    app(NotificationFcmService::class)->sendToCustomer($customer, 'Voucher sắp hết hạn', $body, 'caro_voucher_expiring', [], '/coupons');
                }
            } catch (\Throwable $e) {
                Log::warning('Caro: nhắc voucher hết hạn thất bại', ['customer_id' => $items->first()->customer_id, 'error' => $e->getMessage()]);
            }

            CaroReward::whereKey($items->pluck('id'))->update(['expiry_reminded_at' => now()]);
        }

        return $rewards->groupBy('customer_id')->count();
    }

    // Các giải đang bật còn lượt (daily_limit / total_limit); giải dự phòng không giới hạn. Gọi trong transaction.
    private function availablePrizes(): \Illuminate\Support\Collection
    {
        $prizes = CaroPrize::where('is_active', true)->with('categories:id')->lockForUpdate()->get();

        $today = CaroReward::where('created_at', '>=', now()->startOfDay())->whereNotNull('prize_id')
            ->selectRaw('prize_id, COUNT(*) as c')->groupBy('prize_id')->pluck('c', 'prize_id');

        return $prizes->filter(fn (CaroPrize $p) => $p->is_fallback
            || (($p->daily_limit === null || (int) ($today[$p->id] ?? 0) < $p->daily_limit)
                && ($p->total_limit === null || $p->awarded_count < $p->total_limit)));
    }

    // Bốc 1 giải theo trọng số trong các giải còn lượt.
    private function drawPrize(\Illuminate\Support\Collection $available): ?CaroPrize
    {
        $pool = $available->filter(fn (CaroPrize $p) => $p->weight > 0);

        if ($pool->isEmpty()) {
            return null;
        }

        $roll = random_int(1, (int) $pool->sum('weight'));
        foreach ($pool as $prize) {
            $roll -= $prize->weight;
            if ($roll <= 0) {
                return $prize;
            }
        }

        return $pool->last();
    }

    private function createCoupon(Customer $customer, CaroPrize $prize): Coupon
    {
        do {
            $code = 'CR' . strtoupper(Str::random(6));
        } while (Coupon::withoutGlobalScopes()->where('code', $code)->exists());

        $coupon = Coupon::create([
            'partner_id'      => null,
            'funded_by'       => Coupon::FUNDED_PLATFORM,
            'source'          => 'caro',
            'code'            => $code,
            'name'            => $prize->label,
            'description'     => 'Voucher thưởng mini game Caro',
            'type'            => $prize->coupon_type ?: 'fixed',
            'value'           => $prize->coupon_value,
            'apply_type'      => 'all_rooms',
            'min_order_value' => $prize->min_order_value,
            'max_discount'    => $prize->max_discount,
            'usage_limit'     => 1,
            'used_count'      => 0,
            'start_at'        => now(),
            'end_at'          => now()->addDays($prize->validity_days)->endOfDay(),
            'is_active'       => true,
            'is_exclusive'    => $prize->is_exclusive,
            'customer_id'     => $customer->id,
            'created_by'      => User::role(config('filament-shield.super_admin.name'))->value('id'),
        ]);

        // Phạm vi chi nhánh của giải → voucher (Coupon::appliesToRoom kiểm tra cả khu vực con)
        if ($prize->categories->isNotEmpty()) {
            $coupon->categories()->sync($prize->categories->pluck('id'));
        }

        return $coupon;
    }

    // Người kia trong trận nhận trạng thái mới; $actorId = null thì đẩy cho cả 2 bên.
    private function pushToOpponent(CaroMatch $match, ?string $actorId): void
    {
        foreach (array_filter([$match->player_x_id, $match->player_o_id]) as $playerId) {
            if ((string) $playerId !== $actorId) {
                $this->realtime->push((string) $playerId, 'caro.updated', ['match' => $this->state($match, (string) $playerId)]);
            }
        }
    }

    // "Nguyễn Văn An" → "N. V. An"
    private static function maskName(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        if (! $parts) {
            return 'Người chơi';
        }

        $last = array_pop($parts);

        return trim(implode(' ', array_map(fn (string $p) => mb_substr($p, 0, 1) . '.', $parts)) . ' ' . $last);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['message' => [$message]])->status(422);
    }
}

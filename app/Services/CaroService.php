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
 * Mini game CARO TRỰC TUYẾN (chỉ người với người, 2 hoặc 3 người): ghép ngẫu nhiên khách đang chờ, hoặc khách mời bạn vào đấu — mời
 * đích danh theo SĐT / mã người chơi (bạn nhận push + WS, lời mời hiện trong lời mời đến) hoặc gửi link/mã cho ai cũng được. Chờ quá match_wait_seconds mà không có ai thì app gợi ý mời bạn.
 * Mọi nước đi do BE kiểm tra (đúng lượt, ô trống, hết giờ) và BE phân thắng thua. Tiến độ (pending_wins) chạy theo vòng mốc thưởng: mỗi
 * trận thắng được tính (xem countsForReward), mỗi lần chơi đủ chuỗi ngày và lần bạn được mời chơi trận đầu đều +1; chạm mốc thì nhận voucher
 * (mốc cố định hoặc bốc theo trọng số), chạm mốc cuối thì về 0. Voucher cá nhân, 365home chịu, nằm trong /coupons/mine.
 * Hết giờ lượt được xử lý lười (mỗi lần đọc trận) và bởi lệnh caro:sweep chạy mỗi phút.
 * Trận 3 người (X → O → △): đầu hàng / hết giờ thì bị loại (vẫn xem tiếp được), 2 người còn lại đánh tiếp; còn 1 người thì người đó thắng.
 * Ghép ngẫu nhiên đủ 3 người cùng chờ chế độ 3 người; lời mời 3 người là phòng chờ, đủ 2 bạn nhận mã thì vào trận.
 * Sự kiện WS gom theo request và gửi sau khi trả response (CaroRealtimeService) — nước đi không phải chờ đẩy cho từng người.
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
     * Vào/giữ hàng chờ trận $players người (null = giữ chế độ đang chờ, mặc định 2). Đang có trận dở thì trả trận đó; đủ người khác
     * đang chờ cùng chế độ thì ghép luôn. Đổi chế độ thì tính lại thời gian chờ từ đầu.
     * App hỏi lại mỗi 2-3 giây: lúc chưa đủ người chờ thì chỉ cập nhật last_seen_at, không lấy khoá ghép trận — khoá chỉ dùng khi thật sự
     * ghép, và tách riêng theo chế độ (caro:matchmaking:2 / :3) để 2 hàng chờ không chặn nhau.
     *
     * @return array{status: 'matched', match: CaroMatch}|array{status: 'waiting', player_count: int, waited_seconds: int, suggest_invite: bool}
     */
    public function seek(Customer $customer, ?int $players = null): array
    {
        $setting = $this->enabledSetting();
        $id      = (string) $customer->id;

        if ($match = $this->activeMatch($id)) {
            CaroQueueEntry::whereKey($id)->delete();

            return ['status' => 'matched', 'match' => $match];
        }

        $entry = CaroQueueEntry::find($id);
        if ($matched = $this->takeQueuedMatch($entry)) {
            return ['status' => 'matched', 'match' => $matched];
        }
        if ($entry?->match_id) {
            $entry = null;
        }

        $players ??= $entry?->player_count ?? 2;
        $this->ensurePlayerCount($setting, $players);

        if ($entry && $entry->player_count !== $players) {
            $entry->delete();
            $entry = null;
        }

        // Chưa đủ người chờ cùng chế độ → giữ chỗ, khỏi khoá. Có người vừa vào cùng lúc thì lần hỏi sau sẽ ghép.
        if ($this->waitingQuery($players, $id)->count() < $players - 1) {
            return $this->keepWaiting($entry, $id, $players, $setting);
        }

        $result = Cache::lock("caro:matchmaking:{$players}", 5)->block(3, function () use ($id, $setting, $players) {
            $entry = CaroQueueEntry::find($id);
            if ($matched = $this->takeQueuedMatch($entry)) {
                return ['status' => 'matched', 'match' => $matched, 'paired' => false];
            }
            if ($entry && ($entry->match_id || $entry->player_count !== $players)) {
                $entry->delete();
                $entry = null;
            }

            $opponents = $this->waitingQuery($players, $id)->orderBy('created_at')->limit($players - 1)->get();

            if ($opponents->count() === $players - 1) {
                $match = $this->createMatch($setting, [$id, ...$opponents->pluck('customer_id')->map(fn ($c) => (string) $c)->all()]);
                CaroQueueEntry::whereKey($opponents->pluck('customer_id'))->update(['match_id' => $match->id]);
                $entry?->delete();

                return ['status' => 'matched', 'match' => $match, 'paired' => true];
            }

            return $this->keepWaiting($entry, $id, $players, $setting);
        });

        if (($result['paired'] ?? false) === true) {
            $this->pushMatched($result['match']);
        }
        unset($result['paired']);

        return $result;
    }

    public function leaveQueue(Customer $customer): void
    {
        CaroQueueEntry::whereKey((string) $customer->id)->whereNull('match_id')->delete();
    }

    // Mã mời nhập tay trên app — bỏ ký tự dễ nhầm (0/O, 1/I/L).
    private const INVITE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    // Mã người chơi dài 7 ký tự để không lẫn với mã mời (6 ký tự)
    private const PLAYER_CODE_LENGTH = 7;

    /**
     * Tạo lời mời đấu: người mời gửi mã (hoặc link chứa mã) cho bạn, bạn nhập mã trên app để vào trận.
     * Mỗi khách chỉ giữ 1 lời mời đang chờ: còn hiệu lực thì dùng lại mã đó (gia hạn thêm) để link đã gửi vẫn dùng được,
     * app gọi lại nhiều lần (mở lại màn mời, mời thêm SĐT / mã người chơi khác) cũng không đổi mã. $players = null giữ số người của
     * lời mời đang có (mặc định 2); đổi số người khi chưa ai vào phòng thì vẫn giữ mã.
     */
    public function createInvite(Customer $inviter, ?int $players = null): CaroInvite
    {
        $setting = $this->inviteSetting();
        $id      = (string) $inviter->id;

        if ($this->activeMatch($id)) {
            $this->fail('Bạn đang trong một trận khác.');
        }

        $current = $this->outgoingInvite($inviter);
        $players ??= $current?->player_count ?? 2;
        $this->ensurePlayerCount($setting, $players);

        if ($current && ($current->player_count === $players || $current->guestIds() === [])) {
            $current->update(['player_count' => $players, 'expires_at' => now()->addMinutes($setting->invite_expire_minutes)]);
            CaroQueueEntry::whereKey($id)->whereNull('match_id')->delete();

            return $current;
        }

        if ($current) {
            $this->fail('Đã có bạn vào phòng chờ — huỷ lời mời hiện tại nếu muốn đổi số người chơi.');
        }

        $invite = DB::transaction(function () use ($id, $setting, $players) {
            CaroInvite::where('inviter_id', $id)->where('status', CaroInvite::STATUS_PENDING)
                ->get()
                ->each(fn (CaroInvite $old) => $this->closeInvite($old, CaroInvite::STATUS_CANCELLED));

            do {
                $code = $this->randomCode(6);
            } while (CaroInvite::where('code', $code)->exists());

            return CaroInvite::create([
                'code'         => $code,
                'inviter_id'   => $id,
                'player_count' => $players,
                'status'       => CaroInvite::STATUS_PENDING,
                'expires_at'   => now()->addMinutes($setting->invite_expire_minutes),
            ]);
        });

        CaroQueueEntry::whereKey($id)->whereNull('match_id')->delete();

        return $invite;
    }

    // Mã người chơi cố định của khách (tạo lần đầu cần tới) — bạn bè nhập mã này để mời đích danh.
    public function playerCode(string $customerId): string
    {
        $stat = CaroPlayerStat::find($customerId);
        if ($stat?->player_code) {
            return $stat->player_code;
        }

        // 2 request cùng lúc (mở màn caro lần đầu): chỉ ghi khi chưa có mã, request sau đọc lại mã request trước đã ghi
        for ($attempt = 0; ; $attempt++) {
            $code = $this->randomCode(self::PLAYER_CODE_LENGTH);

            try {
                CaroPlayerStat::firstOrCreate(['customer_id' => $customerId]);
                CaroPlayerStat::whereKey($customerId)->whereNull('player_code')->update(['player_code' => $code]);

                return CaroPlayerStat::whereKey($customerId)->value('player_code');
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }

    // Khách có SĐT này để báo lời mời — tìm trước khi tạo lời mời để sai SĐT thì không tạo / gia hạn gì.
    public function inviteeByPhone(Customer $inviter, string $phone): Customer
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $local  = str_starts_with($digits, '84') ? '0' . substr($digits, 2) : $digits;

        $invitee = $digits === '' ? null
            : Customer::whereIn('phone', array_unique([trim($phone), $digits, $local, app(ZaloOtpService::class)->normalizePhone($local)]))->first();

        if (! $invitee) {
            $this->fail('Không tìm thấy khách hàng với số điện thoại này. Bạn có thể gửi mã / link mời cho bạn.');
        }

        return $this->ensureNotSelf($inviter, $invitee);
    }

    // Khách có mã người chơi này để báo lời mời.
    public function inviteeByPlayerCode(Customer $inviter, string $playerCode): Customer
    {
        $stat    = CaroPlayerStat::where('player_code', strtoupper(trim($playerCode)))->first();
        $invitee = $stat ? Customer::find($stat->customer_id) : null;

        if (! $invitee) {
            $this->fail('Không tìm thấy người chơi với mã này. Kiểm tra lại mã người chơi của bạn bè.');
        }

        return $this->ensureNotSelf($inviter, $invitee);
    }

    /**
     * Báo lời mời cho khách đã nhập SĐT / mã người chơi: WS caro.invited + thông báo caro_invite (push FCM + danh sách thông báo) kèm mã,
     * và ghi vào notified_ids để lời mời hiện trong lời mời đến của họ (GET /invites → incoming) kể cả khi lỡ thông báo.
     * Lời mời vẫn là lời mời mở (ai có mã cũng nhận được). Báo trùng 1 người trong 1 phút thì bỏ qua. Push gửi sau khi trả response.
     */
    public function notifyInvitee(CaroInvite $invite, Customer $inviter, Customer $invitee): void
    {
        $inviteeId = (string) $invitee->id;

        if (! in_array($inviteeId, $invite->notified_ids ?? [], true)) {
            $invite->update(['notified_ids' => [...($invite->notified_ids ?? []), $inviteeId]]);
        }

        if (! Cache::add("caro:invite-notify:{$invite->id}:{$inviteeId}", 1, 60)) {
            return;
        }

        $this->realtime->push($inviteeId, 'caro.invited', fn () => ['invite' => $this->invitePayload($invite, $inviteeId)]);

        $mode = $invite->player_count === 3 ? 'một ván caro 3 người' : 'một ván caro';
        $body = self::maskName($inviter->fullname) . " mời bạn {$mode}. Nhập mã {$invite->code} hoặc bấm vào đây để vào trận!";

        app()->terminating(function () use ($invite, $invitee, $body) {
            try {
                app(NotificationFcmService::class)->sendToCustomer(
                    $invitee, 'Lời mời đấu Caro', $body, 'caro_invite', ['code' => $invite->code], '/games/caro/invites/' . $invite->code,
                );
            } catch (\Throwable $e) {
                Log::warning('Caro: gửi thông báo lời mời thất bại', ['invite' => $invite->code, 'error' => $e->getMessage()]);
            }
        });
    }

    // Lời mời đến: lời mời còn hiệu lực mà mình được báo đích danh (theo SĐT / mã người chơi).
    /** @return \Illuminate\Support\Collection<int, CaroInvite> */
    public function incomingInvites(Customer $customer): \Illuminate\Support\Collection
    {
        return CaroInvite::where('status', CaroInvite::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->whereJsonContains('notified_ids', (string) $customer->id)
            ->latest()
            ->limit(10)
            ->get();
    }

    // Lời mời theo mã — ai có mã cũng xem / nhận được; đã đủ người vào trận thì chỉ những người trong phòng xem được.
    public function findInvite(string $code, Customer $customer): CaroInvite
    {
        $invite = CaroInvite::where('code', strtoupper(trim($code)))->firstOrFail();
        $id     = (string) $customer->id;

        if ($invite->status === CaroInvite::STATUS_ACCEPTED && ! in_array($id, [(string) $invite->inviter_id, ...$invite->guestIds()], true)) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(CaroInvite::class);
        }

        return $this->expireIfDue($invite);
    }

    /**
     * Nhận lời mời → vào phòng. Đủ người (2 người: ngay khi có 1 bạn nhận; 3 người: khi bạn thứ 2 nhận) thì tạo trận, mọi người nhận
     * WS caro.matched; chưa đủ thì những người liên quan nhận WS caro.invite_updated. Trả lời mời (match_id có khi đã vào trận).
     */
    public function acceptInvite(string $code, Customer $customer): CaroInvite
    {
        $setting = $this->inviteSetting();
        $id      = (string) $customer->id;
        $invite  = $this->findInvite($code, $customer);

        if ((string) $invite->inviter_id === $id) {
            $this->fail('Không thể tự nhận lời mời của chính mình.');
        }

        [$invite, $match] = $this->withMatchmakingLocks(fn () => DB::transaction(function () use ($invite, $id, $setting) {
            $invite = CaroInvite::whereKey($invite->id)->lockForUpdate()->first();

            if (in_array($id, $invite->guestIds(), true)) {
                return [$invite, null];
            }
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

            // Bạn đã vào phòng nhưng sau đó vào trận khác thì nhường chỗ
            $guests   = array_values(array_filter($invite->guestIds(), fn (string $guestId) => ! $this->activeMatch($guestId)));
            $guests[] = $id;

            if (count($guests) < $invite->player_count - 1) {
                $invite->update(['guest_ids' => $guests, 'invitee_id' => $guests[0]]);
                CaroQueueEntry::whereKey($id)->whereNull('match_id')->delete();

                return [$invite, null];
            }

            $players = [(string) $invite->inviter_id, ...$guests];
            $match   = $this->createMatch($setting, $players, 'invite');
            $invite->update(['status' => CaroInvite::STATUS_ACCEPTED, 'match_id' => $match->id, 'guest_ids' => $guests, 'invitee_id' => $guests[0]]);
            CaroQueueEntry::whereIn('customer_id', $players)->delete();

            return [$invite, $match];
        }));

        if ($match) {
            $this->pushMatched($match);
        }
        // Người đã được báo mà chưa vào (hoặc đang chờ trong phòng) cập nhật lại lời mời — đủ người thì app ẩn popup lời mời đến
        $this->pushInviteUpdated($invite, $id, $match ? $match->players() : []);

        return $invite;
    }

    // Người mời huỷ lời mời đang chờ; bạn đã vào phòng chờ (lời mời 3 người, chưa đủ người) thì rời phòng.
    public function cancelInvite(string $code, Customer $customer): CaroInvite
    {
        $invite = $this->findInvite($code, $customer);
        $id     = (string) $customer->id;

        if (in_array($id, $invite->guestIds(), true) && $invite->status === CaroInvite::STATUS_PENDING) {
            return $this->leaveInvite($invite, $id);
        }

        if ((string) $invite->inviter_id !== $id) {
            $this->fail('Chỉ người mời mới huỷ được lời mời.');
        }

        return $this->closeOpenInvite($invite, CaroInvite::STATUS_CANCELLED);
    }

    // Lời mời mình đang gửi (tối đa 1).
    public function outgoingInvite(Customer $customer): ?CaroInvite
    {
        return CaroInvite::where('inviter_id', (string) $customer->id)->where('status', CaroInvite::STATUS_PENDING)
            ->where('expires_at', '>', now())->latest()->first();
    }

    public function invitePayload(CaroInvite $invite, string $viewerId): array
    {
        $setting = CaroSetting::current();
        $url     = rtrim($setting->invite_share_url_base, '/') . '/' . $invite->code;
        $mine    = (string) $invite->inviter_id === $viewerId;
        $match   = $invite->match_id ? CaroMatch::find($invite->match_id) : null;
        $guests  = $invite->guestIds();
        $names   = Customer::whereIn('id', [$invite->inviter_id, ...$guests])->pluck('fullname', 'id');
        $mode    = $invite->player_count === 3 ? 'caro 3 người' : 'caro';

        return [
            'code'         => $invite->code,
            'status'       => $invite->isOpen() || $invite->status !== CaroInvite::STATUS_PENDING ? $invite->status : CaroInvite::STATUS_EXPIRED,
            'player_count' => $invite->player_count,
            'is_mine'      => $mine,
            'joined'       => in_array($viewerId, $guests, true),
            'inviter'      => ['name' => self::maskName($names[$invite->inviter_id] ?? null)],
            // Người nhận đầu tiên — app cũ chỉ đọc trường này
            'invitee'      => $guests ? ['name' => self::maskName($names[$guests[0]] ?? null)] : null,
            'guests'       => array_map(fn (string $guestId) => ['name' => self::maskName($names[$guestId] ?? null)], $guests),
            'slots_left'   => $invite->slotsLeft(),
            'expires_at'   => $invite->expires_at->toIso8601String(),
            'seconds_left' => max(0, (int) now()->diffInSeconds($invite->expires_at, false)),
            'share_url'    => $mine ? $url : null,
            'share_text'   => $mine ? "Đấu {$mode} với mình trên app 365Home nhé! Mở app → Mini game Caro → nhập mã {$invite->code}. Chưa có app thì tải tại: {$url}" : null,
            'match'        => $match && $match->sideOf($viewerId) ? $this->state($match, $viewerId) : null,
        ];
    }

    // Đọc trận của khách (cả người đã bị loại, để xem tiếp) — tiện thể xử thua nếu lượt hiện tại đã hết giờ.
    public function find(string $matchId, Customer $customer): CaroMatch
    {
        $match = CaroMatch::whereKey($matchId)
            ->ofPlayer((string) $customer->id)
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
                $this->eliminate($match, $match->turn(), 'timeout');

                return $match;
            }

            if ($match->isEliminated($side)) {
                $this->fail('Bạn đã bị loại khỏi trận này.');
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
            $side  = $match->sideOf((string) $customer->id);

            if ($match->isEliminated($side)) {
                $this->fail('Bạn đã bị loại khỏi trận này.');
            }

            $this->eliminate($match, $side, 'resign');

            return $match;
        });

        $this->pushToOpponent($match, (string) $customer->id);

        return $match;
    }

    // Xử thua (trận 3 người: loại) bên đang tới lượt nếu đã quá giờ. Trả null nếu trận không còn hết giờ (vd vừa có nước đi).
    public function resolveTimeout(string $matchId): ?CaroMatch
    {
        $match = DB::transaction(function () use ($matchId) {
            $match = CaroMatch::whereKey($matchId)->lockForUpdate()->first();

            if (! $match || ! $this->isTimedOut($match)) {
                return null;
            }

            $this->eliminate($match, $match->turn(), 'timeout');

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
            ->ofPlayer($customerId)
            ->latest()
            ->get()
            // Đã bị loại khỏi trận 3 người đang tiếp tục thì coi như không còn trận dở
            ->first(fn (CaroMatch $match) => ! $match->isEliminated($match->sideOf($customerId)));
    }

    /** @return array{waiting: int, playing: int} */
    public function onlineCount(): array
    {
        $playing = (int) CaroMatch::where('status', CaroMatch::STATUS_PLAYING)
            ->where('updated_at', '>=', now()->subMinutes(2))->sum('player_count');

        return [
            'waiting' => CaroQueueEntry::whereNull('match_id')->where('last_seen_at', '>=', now()->subSeconds(self::QUEUE_ALIVE_SECONDS))->count(),
            'playing' => $playing,
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
        $side     = $match->sideOf($customerId);
        $players  = $match->players();
        $names    = Customer::whereIn('id', $players)->pluck('fullname', 'id');
        $finished = $match->status === CaroMatch::STATUS_FINISHED;
        $rewards  = $finished ? CaroReward::where('match_id', $match->id)->where('customer_id', $customerId)->with('coupon')->get() : collect();
        $others   = array_filter($players, fn (string $s) => $s !== $side, ARRAY_FILTER_USE_KEY);

        return [
            'id'                => $match->id,
            'status'            => $match->status,
            'board_size'        => $match->board_size,
            'win_length'        => $match->win_length,
            'block_two_ends'    => $match->block_two_ends,
            'turn_seconds'      => $match->turn_seconds,
            'player_count'      => $match->player_count,
            'you'               => $side,
            'source'            => $match->source,
            // Trận 3 người: tên 2 đối thủ ghép bằng " & " — chi tiết từng người ở players
            'opponent'          => ['name' => implode(' & ', array_map(fn (string $id) => self::maskName($names[$id] ?? null), $others))],
            // Các quân theo thứ tự đi (x → o → t), eliminated = đã đầu hàng / hết giờ ở trận 3 người
            'players'           => array_values(array_map(fn (string $s, string $id) => [
                'side'       => $s,
                'name'       => self::maskName($names[$id] ?? null),
                'is_you'     => $s === $side,
                'eliminated' => $match->isEliminated($s),
            ], array_keys($players), $players)),
            'eliminated'        => $match->isEliminated($side),
            'moves'             => $match->moves,
            'last_move'         => Arr::last($match->moves),
            'turn'              => $finished ? null : $match->turn(),
            'your_turn'         => ! $finished && $side !== null && $match->turn() === $side,
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

    /** @param array<int, string> $playerIds 2 hoặc 3 khách */
    private function createMatch(CaroSetting $setting, array $playerIds, string $source = 'queue'): CaroMatch
    {
        // Bốc ngẫu nhiên thứ tự đi (người đầu cầm X, đi trước)
        [$x, $o, $t] = [...Arr::shuffle($playerIds), null];

        return CaroMatch::create([
            'player_x_id'      => $x,
            'player_o_id'      => $o,
            'player_t_id'      => $t,
            'player_count'     => count($playerIds),
            'source'           => $source,
            'board_size'       => $setting->board_size,
            'win_length'       => $setting->win_length,
            'block_two_ends'   => $setting->block_two_ends,
            'turn_seconds'     => $setting->turn_seconds,
            'moves'            => [],
            'eliminated'       => [],
            'status'           => CaroMatch::STATUS_PLAYING,
            'turn_deadline_at' => now()->addSeconds($setting->turn_seconds + self::FIRST_TURN_BONUS_SECONDS),
        ]);
    }

    private function ensurePlayerCount(CaroSetting $setting, int $players): void
    {
        if (! in_array($players, [2, 3], true)) {
            $this->fail('Số người chơi chỉ được là 2 hoặc 3.');
        }
        if ($players === 3 && ! $setting->three_player_enabled) {
            $this->fail('Chế độ caro 3 người đang tạm đóng.');
        }
    }

    // Những người khác đang chờ trận $players người (còn hỏi hàng chờ gần đây, chưa được ghép)
    private function waitingQuery(int $players, string $exceptId): \Illuminate\Database\Eloquent\Builder
    {
        return CaroQueueEntry::where('player_count', $players)
            ->whereNull('match_id')
            ->where('last_seen_at', '>=', now()->subSeconds(self::QUEUE_ALIVE_SECONDS))
            ->where('customer_id', '!=', $exceptId);
    }

    // Dòng chờ đã được người khác ghép vào trận → lấy trận đó (xoá dòng chờ). Trận đã kết thúc khi khách chưa kịp hỏi lại thì bỏ qua.
    private function takeQueuedMatch(?CaroQueueEntry $entry): ?CaroMatch
    {
        if (! $entry?->match_id) {
            return null;
        }

        $entry->delete();
        $match = CaroMatch::find($entry->match_id);

        return $match?->status === CaroMatch::STATUS_PLAYING ? $match : null;
    }

    private function keepWaiting(?CaroQueueEntry $entry, string $id, int $players, CaroSetting $setting): array
    {
        $entry ??= new CaroQueueEntry(['customer_id' => $id, 'player_count' => $players]);
        $entry->last_seen_at = now();
        $entry->save();

        $waited = (int) $entry->created_at->diffInSeconds(now());

        return [
            'status'         => 'waiting',
            'player_count'   => $players,
            'waited_seconds' => $waited,
            'suggest_invite' => $setting->invite_enabled && $waited >= $setting->match_wait_seconds,
        ];
    }

    // Nhận lời mời giữ cả 2 khoá hàng chờ (luôn theo thứ tự 2 → 3) để người mời / bạn không bị ghép ngẫu nhiên vào trận khác cùng lúc.
    private function withMatchmakingLocks(callable $callback): mixed
    {
        return Cache::lock('caro:matchmaking:2', 5)->block(3, fn () => Cache::lock('caro:matchmaking:3', 5)->block(3, $callback));
    }

    private function randomCode(int $length): string
    {
        return collect(range(1, $length))->map(fn () => self::INVITE_ALPHABET[random_int(0, strlen(self::INVITE_ALPHABET) - 1)])->implode('');
    }

    private function ensureNotSelf(Customer $inviter, Customer $invitee): Customer
    {
        if ((string) $invitee->id === (string) $inviter->id) {
            $this->fail('Không thể tự mời chính mình.');
        }

        return $invitee;
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

    // Đóng lời mời và báo cho bên còn lại (người mời khi bị từ chối; người trong phòng chờ và người được báo đích danh khi bị huỷ)
    private function closeInvite(CaroInvite $invite, string $status): void
    {
        $invite->update(['status' => $status]);

        if ($status === CaroInvite::STATUS_DECLINED) {
            $this->realtime->push((string) $invite->inviter_id, 'caro.invite_updated', fn () => ['invite' => $this->invitePayload($invite, (string) $invite->inviter_id)]);

            return;
        }

        $this->pushInviteUpdated($invite, (string) $invite->inviter_id);
    }

    // Bạn rời phòng chờ của lời mời 3 người (chưa đủ người); người mời và người còn lại nhận caro.invite_updated.
    private function leaveInvite(CaroInvite $invite, string $guestId): CaroInvite
    {
        DB::transaction(function () use (&$invite, $guestId) {
            $invite = CaroInvite::whereKey($invite->id)->lockForUpdate()->first();

            if ($invite->status !== CaroInvite::STATUS_PENDING) {
                $this->fail('Lời mời không còn hiệu lực.');
            }

            $guests = array_values(array_diff($invite->guestIds(), [$guestId]));
            $invite->update(['guest_ids' => $guests, 'invitee_id' => $guests[0] ?? null]);
        });

        $this->pushInviteUpdated($invite, $guestId);

        return $invite;
    }

    // Báo lời mời thay đổi (có người vào / rời phòng, đủ người, bị huỷ) cho người mời, người trong phòng chờ và người được báo đích danh,
    // trừ $actorId và $except (vd những người vừa nhận caro.matched).
    private function pushInviteUpdated(CaroInvite $invite, string $actorId, array $except = []): void
    {
        $recipients = array_unique([(string) $invite->inviter_id, ...$invite->guestIds(), ...array_map('strval', $invite->notified_ids ?? [])]);

        foreach (array_diff($recipients, [$actorId, ...$except]) as $customerId) {
            $this->realtime->push($customerId, 'caro.invite_updated', fn () => ['invite' => $this->invitePayload($invite, $customerId)]);
        }
    }

    private function lockedMatchOf(string $matchId, Customer $customer): CaroMatch
    {
        $match = CaroMatch::whereKey($matchId)
            ->ofPlayer((string) $customer->id)
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
        // Trận 3 người lưu kèm quân vì lượt không còn suy ra được từ số nước khi có người bị loại
        $match->moves = [...$match->moves, $match->player_count === 3 ? [$x, $y, $side] : [$x, $y]];

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

    // Loại 1 quân (đầu hàng / hết giờ). Còn 1 quân thì quân đó thắng; trận 3 người còn 2 quân thì đánh tiếp, bị loại đúng lượt mình
    // thì người kế tiếp được tính giờ lại từ đầu.
    private function eliminate(CaroMatch $match, string $side, string $reason): void
    {
        $wasTurn           = $match->turn() === $side;
        $match->eliminated = [...($match->eliminated ?? []), $side];
        $active            = $match->activeSides();

        if (count($active) === 1) {
            $this->finish($match, $active[0], $reason);

            return;
        }

        if ($wasTurn) {
            $match->turn_deadline_at = now()->addSeconds($match->turn_seconds);
        }
        $match->save();
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

        foreach ($match->players() as $playerId) {
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
            foreach ($match->players() as $playerId) {
                $this->touchStreak($playerId, $match, $setting);
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

    // Mỗi bạn được mời (chưa từng chơi caro) chơi xong trận đầu với người mời → bạn đó và người mời +1 trận thưởng (trận 3 người có thể
    // 2 bạn mới cùng lúc); người mời giới hạn số lần / ngày.
    private function inviteBonus(CaroMatch $match, CaroSetting $setting): void
    {
        if (! $setting->invite_bonus_enabled || $match->source !== 'invite') {
            return;
        }

        $invite = CaroInvite::where('match_id', $match->id)->first();
        if (! $invite) {
            return;
        }

        $inviterId = (string) $invite->inviter_id;

        foreach ($invite->guestIds() as $inviteeId) {
            $playedBefore = CaroMatch::where('status', CaroMatch::STATUS_FINISHED)->whereKeyNot($match->id)->ofPlayer($inviteeId)->exists();

            if ($playedBefore || CaroBonus::where('customer_id', $inviteeId)->where('type', CaroBonus::TYPE_INVITE)->exists()) {
                continue;
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

        // Trận 3 người: đã thắng đủ số lần trước 1 trong 2 người thua thì không tính
        if ($setting->same_opponent_daily_cap !== null) {
            foreach (array_diff($match->players(), [$winnerId]) as $loserId) {
                if ((clone $todayWins)->ofPlayer($loserId)->count() >= $setting->same_opponent_daily_cap) {
                    return false;
                }
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

    // Những người khác trong trận (kể cả người đã bị loại, đang xem) nhận trạng thái mới; $actorId = null thì đẩy cho tất cả.
    // Trạng thái dựng sau khi trả response (CaroRealtimeService).
    private function pushToOpponent(CaroMatch $match, ?string $actorId): void
    {
        foreach ($match->players() as $playerId) {
            if ($playerId !== $actorId) {
                $this->realtime->push($playerId, 'caro.updated', fn () => ['match' => $this->state($match, $playerId)]);
            }
        }
    }

    private function pushMatched(CaroMatch $match): void
    {
        foreach ($match->players() as $playerId) {
            $this->realtime->push($playerId, 'caro.matched', fn () => ['match' => $this->state($match, $playerId)]);
        }
    }

    // Tên hiển thị cho người khác thấy (đã che), vd. báo "Đã gửi lời mời cho N. V. An".
    public function publicName(Customer $customer): string
    {
        return self::maskName($customer->fullname);
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

<?php

declare(strict_types=1);

namespace App\Services;

use App\Games\TienLen\GameException;
use App\Models\Customer;
use App\Models\TienLenMatch;
use App\Models\TienLenRoom;
use App\Models\TienLenRoomSeat;
use App\Models\TienLenSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PHÒNG TIẾN LÊN NHIỀU NGƯỜI.
 *  - Tạo phòng (2–4 chỗ, công khai / riêng tư) ⇒ có mã 6 ký tự để mời. Vào bằng mã, hoặc "ghép nhanh" vào phòng công khai còn chỗ (không có thì tự tạo).
 *  - Chủ phòng mời bot vào ghế trống, mời ra bot / người; mọi người bấm sẵn sàng, chủ phòng bắt đầu (≥ 2 ghế có người/bot).
 *  - Mỗi khách chỉ ở 1 phòng: vào phòng khác thì tự rời phòng đang chờ; đang chơi dở thì phải rời phòng đó trước.
 *  - Người trong phòng chờ không hỏi thăm (GET phòng) quá STALE_SECONDS ⇒ bị bỏ ghế; phòng chờ không ai hỏi thăm quá IDLE_CLOSE_MINUTES ⇒ đóng.
 *  - Chủ phòng rời ⇒ chuyển chủ cho người kế tiếp; không còn người ⇒ đóng phòng.
 * Ván chơi chạy ở TienLenService (createRoomMatch / leaveMatch).
 */
class TienLenRoomService
{
    public const STALE_SECONDS      = 90;
    public const ONLINE_SECONDS     = 20;
    public const IDLE_CLOSE_MINUTES = 15;

    private const CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly TienLenService $game)
    {
    }

    /** Phòng công khai đang chờ, còn chỗ, có người đang online. */
    public function publicRooms(?int $maxPlayers = null): Collection
    {
        return TienLenRoom::where('status', TienLenRoom::STATUS_WAITING)->where('is_public', true)
            ->when($maxPlayers, fn ($q) => $q->where('max_players', $maxPlayers))
            ->whereHas('seats', fn ($q) => $q->where('is_bot', false)->where('last_seen_at', '>=', now()->subSeconds(self::STALE_SECONDS)))
            ->withCount('seats')->with(['seats.customer:id,fullname'])->latest()->limit(50)->get()
            ->filter(fn (TienLenRoom $r) => $r->seats_count < $r->max_players)->values();
    }

    public function create(Customer $customer, int $maxPlayers, bool $isPublic): TienLenRoom
    {
        $this->assertEnabled();
        if ($maxPlayers < 2 || $maxPlayers > 4) {
            throw new GameException('Phòng chỉ từ 2 đến 4 người.');
        }

        return DB::transaction(function () use ($customer, $maxPlayers, $isPublic) {
            $this->leaveOtherRooms($customer, null);

            $room = TienLenRoom::create([
                'code' => $this->newCode(), 'host_customer_id' => $customer->id, 'status' => TienLenRoom::STATUS_WAITING,
                'max_players' => $maxPlayers, 'is_public' => $isPublic,
            ]);
            TienLenRoomSeat::create(['room_id' => $room->id, 'seat' => 0, 'customer_id' => $customer->id, 'is_ready' => true, 'last_seen_at' => now()]);

            return $room;
        });
    }

    public function joinByCode(Customer $customer, string $code): TienLenRoom
    {
        $this->assertEnabled();
        $id = TienLenRoom::where('code', strtoupper(trim($code)))->where('status', '!=', TienLenRoom::STATUS_CLOSED)->value('id');
        if (! $id) {
            throw new GameException('Không tìm thấy phòng với mã này.');
        }

        return $this->join($customer, $id);
    }

    // Ghép nhanh: vào phòng công khai đông người nhất còn chỗ; không có thì tạo phòng công khai mới (mặc định 4 chỗ).
    public function quickJoin(Customer $customer, ?int $maxPlayers): TienLenRoom
    {
        $this->assertEnabled();

        foreach ($this->publicRooms($maxPlayers)->sortByDesc('seats_count') as $room) {
            if ($room->seats->contains('customer_id', $customer->id)) {
                return $room;
            }
            try {
                return $this->join($customer, $room->id);
            } catch (GameException) {
                continue; // vừa có người khác vào đủ chỗ
            }
        }

        return $this->create($customer, $maxPlayers ?: 4, true);
    }

    public function current(Customer $customer): ?TienLenRoom
    {
        return TienLenRoom::where('status', '!=', TienLenRoom::STATUS_CLOSED)
            ->whereHas('seats', fn ($q) => $q->where('customer_id', $customer->id))->latest()->first();
    }

    /**
     * Xem phòng (kiêm báo "tôi còn ở đây"): trạng thái phòng + ván hiện tại theo góc nhìn của khách + sự kiện ván có event_no > $after.
     * Ván mới bắt đầu (match id khác lần trước) thì app gửi lại after = 0.
     *
     * @return array{room: TienLenRoom, match: ?TienLenMatch, events: array}
     */
    public function view(Customer $customer, string $roomId, int $after = 0): array
    {
        $room = $this->memberRoom($customer, $roomId);
        TienLenRoomSeat::where('room_id', $room->id)->where('customer_id', $customer->id)->update(['last_seen_at' => now()]);

        if ($room->status === TienLenRoom::STATUS_WAITING) {
            $this->pruneStale($room->id);
            $room->refresh();
        }

        $matchId = $room->current_match_id ?? $room->last_match_id;
        $match   = $matchId ? TienLenMatch::find($matchId) : null;

        // Xử lý lượt quá giờ / đưa người bị bot đánh thay quay lại (nếu khách là người chơi của ván)
        if ($match && $match->status === TienLenMatch::STATUS_PLAYING && $match->seatOf((string) $customer->id) !== null) {
            $match = $this->game->sync($customer, $match->id)['match'];
            $room->refresh();
        }

        return ['room' => $room, 'match' => $match, 'events' => $match ? $this->game->eventsAfter($match, $after) : []];
    }

    public function setReady(Customer $customer, string $roomId, bool $ready): TienLenRoom
    {
        return $this->mutateRoom($customer, $roomId, function (TienLenRoom $room, TienLenRoomSeat $mine) use ($ready) {
            $this->assertWaiting($room);
            $mine->update(['is_ready' => $ready || $room->host_customer_id === $mine->customer_id]);
        });
    }

    public function addBots(Customer $customer, string $roomId, int $count): TienLenRoom
    {
        return $this->mutateRoom($customer, $roomId, function (TienLenRoom $room) use ($count, $customer) {
            $this->assertHostWaiting($room, (string) $customer->id);
            $free = $this->freeSeats($room);
            if ($free === []) {
                throw new GameException('Phòng đã đủ chỗ.');
            }
            foreach (array_slice($free, 0, max(1, $count)) as $seat) {
                TienLenRoomSeat::create(['room_id' => $room->id, 'seat' => $seat, 'is_bot' => true, 'is_ready' => true]);
            }
        });
    }

    public function removeSeat(Customer $customer, string $roomId, int $seat): TienLenRoom
    {
        return $this->mutateRoom($customer, $roomId, function (TienLenRoom $room, TienLenRoomSeat $mine) use ($seat, $customer) {
            $this->assertHostWaiting($room, (string) $customer->id);
            if ($seat === $mine->seat) {
                throw new GameException('Muốn rời phòng thì bấm Rời phòng.');
            }
            $deleted = TienLenRoomSeat::where('room_id', $room->id)->where('seat', $seat)->delete();
            if (! $deleted) {
                throw new GameException('Ghế này đang trống.');
            }
        });
    }

    /** @return array{room: TienLenRoom, match: TienLenMatch, events: array} */
    public function start(Customer $customer, string $roomId): array
    {
        $result = DB::transaction(function () use ($customer, $roomId) {
            $room = $this->lockMemberRoom($customer, $roomId);
            $this->assertHostWaiting($room, (string) $customer->id);

            $seats = TienLenRoomSeat::where('room_id', $room->id)->with('customer:id,fullname')->orderBy('seat')->get();
            if ($seats->count() < 2) {
                throw new GameException('Cần ít nhất 2 người hoặc bot để bắt đầu.');
            }
            $notReady = $seats->filter(fn (TienLenRoomSeat $s) => ! $s->is_bot && ! $s->is_ready && $s->customer_id !== $room->host_customer_id);
            if ($notReady->isNotEmpty()) {
                throw new GameException('Còn người chưa sẵn sàng: ' . $notReady->map(fn ($s) => $s->customer?->fullname ?: 'Ghế ' . ($s->seat + 1))->join(', ') . '.');
            }

            ['match' => $match, 'events' => $events] = $this->game->createRoomMatch($room, $seats->all());

            // Tới trắng ngay khi chia ⇒ ván đã xong, phòng vẫn ở trạng thái chờ
            if ($match->status === TienLenMatch::STATUS_PLAYING) {
                $room->update(['status' => TienLenRoom::STATUS_PLAYING, 'current_match_id' => $match->id]);
            } else {
                $room->update(['last_match_id' => $match->id]);
                TienLenRoomSeat::where('room_id', $room->id)->where('is_bot', false)->where('customer_id', '!=', $room->host_customer_id)->update(['is_ready' => false]);
            }

            return ['room' => $room->fresh(), 'match' => $match, 'events' => $events];
        });

        $this->game->notify($roomId, 'match');

        return $result;
    }

    public function leave(Customer $customer, string $roomId): void
    {
        DB::transaction(function () use ($customer, $roomId) {
            $room = $this->lockMemberRoom($customer, $roomId);
            $this->leaveLocked($room, (string) $customer->id);
        });
    }

    // Đóng phòng chờ không ai hỏi thăm quá lâu.
    public function closeIdle(int $minutes = self::IDLE_CLOSE_MINUTES): int
    {
        $count = 0;
        TienLenRoom::where('status', TienLenRoom::STATUS_WAITING)
            ->whereDoesntHave('seats', fn ($q) => $q->where('is_bot', false)->where('last_seen_at', '>=', now()->subMinutes($minutes)))
            ->pluck('id')->each(function (string $id) use (&$count) {
                DB::transaction(function () use ($id, &$count) {
                    $room = TienLenRoom::whereKey($id)->where('status', TienLenRoom::STATUS_WAITING)->lockForUpdate()->first();
                    if ($room) {
                        $this->close($room);
                        $count++;
                    }
                });
            });

        return $count;
    }

    public static function payload(TienLenRoom $room, string $viewerId): array
    {
        $rows    = TienLenRoomSeat::where('room_id', $room->id)->with('customer:id,fullname')->get()->keyBy('seat');
        $mine    = $rows->firstWhere('customer_id', $viewerId);
        $isHost  = $room->host_customer_id === $viewerId;
        $ready   = $rows->every(fn (TienLenRoomSeat $s) => $s->is_bot || $s->is_ready || $s->customer_id === $room->host_customer_id);

        $seats = [];
        for ($i = 0; $i < $room->max_players; $i++) {
            $s = $rows->get($i);
            $seats[] = $s ? [
                'seat'     => $i,
                'empty'    => false,
                'is_bot'   => $s->is_bot,
                'name'     => $s->is_bot ? 'Máy ' . ($i + 1) : ($s->customer?->fullname ?: 'Khách'),
                'is_me'    => $s->customer_id === $viewerId,
                'is_host'  => ! $s->is_bot && $s->customer_id === $room->host_customer_id,
                'is_ready' => $s->is_bot || $s->is_ready || $s->customer_id === $room->host_customer_id,
                'online'   => $s->is_bot || ($s->last_seen_at && $s->last_seen_at->gte(now()->subSeconds(self::ONLINE_SECONDS))),
            ] : ['seat' => $i, 'empty' => true];
        }

        return [
            'id'               => $room->id,
            'code'             => $room->code,
            'status'           => $room->status,
            'is_public'        => $room->is_public,
            'max_players'      => $room->max_players,
            'my_seat'          => $mine?->seat,
            'is_host'          => $isHost,
            'is_ready'         => (bool) ($mine?->is_ready || $isHost),
            'can_start'        => $isHost && $room->status === TienLenRoom::STATUS_WAITING && $rows->count() >= 2 && $ready,
            'seats'            => $seats,
            'current_match_id' => $room->current_match_id,
            'last_match_id'    => $room->last_match_id,
            'created_at'       => $room->created_at?->toDateTimeString(),
        ];
    }

    // =========================================================
    // PRIVATE
    // =========================================================

    private function assertEnabled(): void
    {
        if (! TienLenSetting::current()->is_enabled) {
            throw new GameException('Mini game Tiến lên đang tạm đóng.');
        }
    }

    private function join(Customer $customer, string $roomId): TienLenRoom
    {
        $room = DB::transaction(function () use ($customer, $roomId) {
            $room = TienLenRoom::whereKey($roomId)->lockForUpdate()->firstOrFail();
            if (TienLenRoomSeat::where('room_id', $room->id)->where('customer_id', $customer->id)->exists()) {
                return $room;
            }

            $this->leaveOtherRooms($customer, $room->id);

            if ($room->status === TienLenRoom::STATUS_PLAYING) {
                throw new GameException('Phòng đang chơi, vui lòng đợi hết ván.');
            }
            if ($room->status !== TienLenRoom::STATUS_WAITING) {
                throw new GameException('Phòng đã đóng.');
            }

            $this->pruneStale($room->id);
            $free = $this->freeSeats($room);
            if ($free === []) {
                throw new GameException('Phòng đã đủ người.');
            }

            TienLenRoomSeat::create(['room_id' => $room->id, 'seat' => $free[0], 'customer_id' => $customer->id, 'last_seen_at' => now()]);

            return $room;
        });

        $this->game->notify($room->id, 'room');

        return $room;
    }

    private function leaveOtherRooms(Customer $customer, ?string $exceptRoomId): void
    {
        $roomIds = TienLenRoomSeat::where('customer_id', $customer->id)->when($exceptRoomId, fn ($q) => $q->where('room_id', '!=', $exceptRoomId))->pluck('room_id');

        foreach ($roomIds as $id) {
            $room = TienLenRoom::whereKey($id)->lockForUpdate()->first();
            if (! $room || $room->status === TienLenRoom::STATUS_CLOSED) {
                continue;
            }
            if ($room->status === TienLenRoom::STATUS_PLAYING) {
                throw new GameException("Bạn đang chơi dở ở phòng {$room->code}, hãy rời phòng đó trước.");
            }
            $this->leaveLocked($room, (string) $customer->id);
        }
    }

    private function leaveLocked(TienLenRoom $room, string $customerId): void
    {
        if ($room->status === TienLenRoom::STATUS_PLAYING && $room->current_match_id) {
            $this->game->leaveMatch(TienLenMatch::find($room->current_match_id), $customerId);
            $room->refresh();
        }

        TienLenRoomSeat::where('room_id', $room->id)->where('customer_id', $customerId)->delete();

        $humans = TienLenRoomSeat::where('room_id', $room->id)->where('is_bot', false)->orderBy('seat')->get();
        if ($humans->isEmpty()) {
            $this->close($room);

            return;
        }

        if ($room->host_customer_id === $customerId) {
            $room->update(['host_customer_id' => $humans->first()->customer_id]);
            $humans->first()->update(['is_ready' => true]);
        }

        $this->game->notify($room->id, 'room');
    }

    private function close(TienLenRoom $room): void
    {
        TienLenRoomSeat::where('room_id', $room->id)->delete();
        $room->update(['status' => TienLenRoom::STATUS_CLOSED, 'current_match_id' => null]);
        $this->game->notify($room->id, 'closed');
    }

    // Bỏ ghế những người không còn hỏi thăm phòng chờ (tắt app / mất mạng).
    private function pruneStale(string $roomId): void
    {
        DB::transaction(function () use ($roomId) {
            $room = TienLenRoom::whereKey($roomId)->lockForUpdate()->first();
            if (! $room || $room->status !== TienLenRoom::STATUS_WAITING) {
                return;
            }
            TienLenRoomSeat::where('room_id', $roomId)->where('is_bot', false)
                ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subSeconds(self::STALE_SECONDS)))
                ->pluck('customer_id')->each(fn (string $cid) => $this->leaveLocked($room->refresh(), $cid));
        });
    }

    private function mutateRoom(Customer $customer, string $roomId, \Closure $fn): TienLenRoom
    {
        $room = DB::transaction(function () use ($customer, $roomId, $fn) {
            $room = $this->lockMemberRoom($customer, $roomId);
            $mine = TienLenRoomSeat::where('room_id', $room->id)->where('customer_id', $customer->id)->first();
            $mine->update(['last_seen_at' => now()]);
            $fn($room, $mine);

            return $room->fresh();
        });

        $this->game->notify($room->id, 'room');

        return $room;
    }

    private function memberRoom(Customer $customer, string $roomId): TienLenRoom
    {
        return TienLenRoom::whereKey($roomId)->whereHas('seats', fn ($q) => $q->where('customer_id', $customer->id))->firstOrFail();
    }

    private function lockMemberRoom(Customer $customer, string $roomId): TienLenRoom
    {
        $room = TienLenRoom::whereKey($roomId)->lockForUpdate()->firstOrFail();
        if (! TienLenRoomSeat::where('room_id', $room->id)->where('customer_id', $customer->id)->exists()) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(TienLenRoom::class);
        }

        return $room;
    }

    private function assertWaiting(TienLenRoom $room): void
    {
        if ($room->status !== TienLenRoom::STATUS_WAITING) {
            throw new GameException('Phòng đang chơi.');
        }
    }

    private function assertHostWaiting(TienLenRoom $room, string $customerId): void
    {
        $this->assertWaiting($room);
        if ($room->host_customer_id !== $customerId) {
            throw new GameException('Chỉ chủ phòng được làm việc này.');
        }
    }

    /** @return int[] */
    private function freeSeats(TienLenRoom $room): array
    {
        $taken = TienLenRoomSeat::where('room_id', $room->id)->pluck('seat')->all();

        return array_values(array_diff(range(0, $room->max_players - 1), $taken));
    }

    private function newCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::CODE_CHARS[random_int(0, strlen(self::CODE_CHARS) - 1)];
            }
        } while (TienLenRoom::where('code', $code)->exists());

        return $code;
    }
}

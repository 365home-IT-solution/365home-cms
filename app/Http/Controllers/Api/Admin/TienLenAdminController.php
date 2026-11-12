<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Games\TienLen\Card;
use App\Http\Controllers\Controller;
use App\Models\GamePointTransaction;
use App\Models\TienLenMatch;
use App\Models\TienLenMatchPlayer;
use App\Models\TienLenMove;
use App\Models\TienLenRoom;
use App\Models\TienLenRoomSeat;
use App\Models\TienLenSetting;
use App\Services\TienLenService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Quản trị mini game Tiến lên (chỉ Super Admin) — cùng dữ liệu với Filament "Tiến lên — Ván chơi".
 *
 *  - GET|PUT /api/admin/games/tien-len/settings              bật/tắt, giây mỗi lượt, điểm theo hạng, đơn vị phạt (unit_*) + points_per_unit, trần điểm/ngày
 *  - GET /api/admin/games/tien-len/matches?status=&customer_id=&search=&from=&to=&per_page=   danh sách ván
 *  - GET /api/admin/games/tien-len/matches/{id}               chi tiết ván: bài chia ban đầu của mọi ghế + nhật ký từng nước
 *  - GET /api/admin/games/tien-len/stats?from=&to=            số ván, tỉ lệ thắng của khách, điểm đã phát
 *  - GET /api/admin/games/tien-len/rooms?status=&search=&per_page=   phòng nhiều người (mã, chủ phòng, ghế)
 */
class TienLenAdminController extends Controller
{
    public function showSettings(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        return response()->json(['data' => TienLenSetting::current()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        $setting = TienLenSetting::current();
        $setting->update($request->validate(TienLenSetting::rules()));

        return response()->json(['data' => $setting->fresh()]);
    }

    public function matches(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        $request->validate([
            'status'      => 'sometimes|nullable|in:playing,finished,abandoned',
            'mode'        => 'sometimes|nullable|in:bot,room',
            'customer_id' => 'sometimes|nullable|string',
            'search'      => 'sometimes|nullable|string|max:100',
            'from'        => 'sometimes|nullable|date',
            'to'          => 'sometimes|nullable|date',
            'per_page'    => 'sometimes|integer|min:1|max:100',
        ]);

        $page = TienLenMatch::query()
            ->with(['customer:id,fullname,phone', 'room:id,code'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->input('mode') === 'bot', fn ($q) => $q->whereNull('room_id'))
            ->when($request->input('mode') === 'room', fn ($q) => $q->whereNotNull('room_id'))
            ->when($request->filled('customer_id'), fn ($q) => $q->where(fn ($w) => $w->where('customer_id', $request->input('customer_id'))
                ->orWhereIn('id', TienLenMatchPlayer::where('customer_id', $request->input('customer_id'))->select('match_id'))))
            ->when($request->filled('search'), fn ($q) => $q->whereHas('customer', fn ($c) => $c
                ->where('fullname', 'like', '%' . $request->input('search') . '%')->orWhere('phone', 'like', '%' . $request->input('search') . '%')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', Carbon::parse($request->input('from'))->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', Carbon::parse($request->input('to'))->endOfDay()))
            ->latest()
            ->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn (TienLenMatch $m) => $this->row($m))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->authorizeSuper($request);

        $match = TienLenMatch::with(['customer:id,fullname,phone', 'room:id,code'])->findOrFail($id);
        $state = $match->state;

        return response()->json(['data' => $this->row($match) + [
            'initial_hands' => array_map(fn (array $h) => Card::payload($h), $state['initial_hands'] ?? []),
            'result'        => $state['result'] ?? null,
            'moves'         => $match->moves()->get()->map(fn (TienLenMove $mv) => [
                'event_no'   => $mv->event_no,
                'seat'       => $mv->seat,
                'type'       => $mv->type,
                'cards'      => $mv->cards ? Card::payload($mv->cards) : null,
                'payload'    => $mv->payload,
                'created_at' => $mv->created_at?->toDateTimeString(),
            ])->values(),
        ]]);
    }

    public function stats(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        $from = Carbon::parse($request->input('from', now()->subDays(30)->toDateString()))->startOfDay();
        $to   = Carbon::parse($request->input('to', now()->toDateString()))->endOfDay();

        $base = TienLenMatch::whereBetween('created_at', [$from, $to]);

        return response()->json(['data' => [
            'from'            => $from->toDateString(),
            'to'              => $to->toDateString(),
            'matches'         => (clone $base)->count(),
            'finished'        => (clone $base)->where('status', TienLenMatch::STATUS_FINISHED)->count(),
            'abandoned'       => (clone $base)->where('status', TienLenMatch::STATUS_ABANDONED)->count(),
            'players'         => (clone $base)->distinct('customer_id')->count('customer_id'),
            'customer_first'  => (clone $base)->where('status', TienLenMatch::STATUS_FINISHED)->where('human_rank', 1)->count(),
            'instant_wins'    => (clone $base)->whereNotNull('instant_win')->count(),
            'by_player_count' => (clone $base)->selectRaw('player_count, COUNT(*) as matches, SUM(CASE WHEN human_rank = 1 AND status = ? THEN 1 ELSE 0 END) as customer_first', [TienLenMatch::STATUS_FINISHED])
                ->groupBy('player_count')->orderBy('player_count')->get()
                ->map(fn ($r) => ['player_count' => (int) $r->player_count, 'matches' => (int) $r->matches, 'customer_first' => (int) $r->customer_first]),
            'points_awarded'  => (int) GamePointTransaction::where('source', GamePointTransaction::SOURCE_TIEN_LEN)->whereBetween('created_at', [$from, $to])->sum('amount'),
        ]]);
    }

    public function rooms(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        $request->validate([
            'status'   => 'sometimes|nullable|in:waiting,playing,closed',
            'search'   => 'sometimes|nullable|string|max:100',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $page = TienLenRoom::with(['host:id,fullname,phone', 'seats.customer:id,fullname'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('code', strtoupper($request->input('search')))
                ->orWhereHas('host', fn ($h) => $h->where('fullname', 'like', '%' . $request->input('search') . '%')->orWhere('phone', 'like', '%' . $request->input('search') . '%'))))
            ->latest()->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn (TienLenRoom $r) => [
                'id'               => $r->id,
                'code'             => $r->code,
                'status'           => $r->status,
                'is_public'        => $r->is_public,
                'max_players'      => $r->max_players,
                'host'             => $r->host ? ['id' => $r->host->id, 'fullname' => $r->host->fullname, 'phone' => $r->host->phone] : null,
                'seats'            => $r->seats->map(fn (TienLenRoomSeat $s) => [
                    'seat' => $s->seat, 'is_bot' => $s->is_bot, 'customer_id' => $s->customer_id, 'name' => $s->is_bot ? 'Máy ' . ($s->seat + 1) : $s->customer?->fullname,
                    'is_ready' => $s->is_ready, 'last_seen_at' => $s->last_seen_at?->toDateTimeString(),
                ])->values(),
                'current_match_id' => $r->current_match_id,
                'last_match_id'    => $r->last_match_id,
                'created_at'       => $r->created_at?->toDateTimeString(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    private function row(TienLenMatch $m): array
    {
        return [
            'id'            => $m->id,
            'customer'      => $m->customer ? ['id' => $m->customer->id, 'fullname' => $m->customer->fullname, 'phone' => $m->customer->phone] : null,
            'player_count'  => $m->player_count,
            'status'        => $m->status,
            'rank'          => $m->human_rank,
            'rank_label'    => $m->human_rank ? TienLenService::rankLabel($m->human_rank, $m->player_count) : null,
            'score'         => $m->human_score,
            'reward_points' => $m->reward_points,
            'instant_win'   => $m->instant_win,
            'is_first_match' => $m->is_first_match,
            'mode'          => $m->room_id ? 'room' : 'bot',
            'room'          => $m->room_id ? ['id' => $m->room_id, 'code' => $m->room?->code] : null,
            'players'       => collect($m->seatList())->map(fn (array $s, int $i) => [
                'seat' => $i, 'name' => $s['name'], 'customer_id' => $s['customer_id'], 'is_bot' => $s['is_bot'], 'left' => $s['left'] ?? false, 'auto' => $s['auto'] ?? false,
            ])->values(),
            'created_at'    => $m->created_at?->toDateTimeString(),
            'finished_at'   => $m->finished_at?->toDateTimeString(),
        ];
    }

    private function authorizeSuper(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }
}

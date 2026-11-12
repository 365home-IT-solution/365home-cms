<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Games\TienLen\Card;
use App\Games\TienLen\GameException;
use App\Games\TienLen\StaleMatchException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\GamePointTransaction;
use App\Models\TienLenMatchPlayer;
use App\Models\TienLenSetting;
use App\Services\GamePointService;
use App\Services\TienLenDeckService;
use App\Services\TienLenRoomService;
use App\Services\TienLenService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mini game Tiến lên miền Nam cho khách đã đăng nhập — ván chơi (cả chơi với bot lẫn ván trong phòng nhiều người, xem TienLenRoomController).
 * BE giữ bài, chạy luật và nước đi của bot — app chỉ gửi lá khách chọn. Mỗi nước đi trả { state, events }: events là chuỗi sự kiện theo
 * thứ tự (có event_no) để app chạy hiệu ứng, state là trạng thái sau cùng THEO GÓC NHÌN người gọi (chỉ có bài của họ).
 * Lá bài: id 0..51 = rank*4 + suit (rank 0..12 = 3..A,2; suit 0..3 = ♠ ♣ ♦ ♥), kèm code dạng "10H". Xem App\Services\TienLenService.
 */
class TienLenController extends Controller
{
    public function __construct(private readonly TienLenService $service)
    {
    }

    /**
     * GET /api/games/tien-len — luật + điểm thưởng + điểm hôm nay + ván với bot đang dở + phòng đang ở (nếu có).
     */
    public function overview(Request $request, GamePointService $points, TienLenRoomService $rooms): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $setting  = TienLenSetting::current();

        return response()->json(['data' => [
            'is_enabled'       => $setting->is_enabled,
            'bots'             => ['min' => 1, 'max' => 3],
            'turn_seconds'     => $setting->turn_seconds,
            'points'           => [
                'first'      => $setting->points_first,
                'second'     => $setting->points_second,
                'third'      => $setting->points_third,
                'last'       => $setting->points_last,
            ],
            // Điểm phạt theo luật: đơn vị chặt / thối / cóng / tới trắng, mỗi đơn vị = points_per_unit điểm ví.
            // Điểm ví mỗi ván = max(0, điểm hạng + điểm phạt × points_per_unit)
            'penalty_units'    => $setting->penaltyUnits(),
            'points_per_unit'  => $setting->points_per_unit,
            'daily_points_cap' => $setting->daily_points_cap,
            'earned_today'     => $points->earnedToday((string) $customer->id, GamePointTransaction::SOURCE_TIEN_LEN),
            'balance'          => $points->balance((string) $customer->id),
            'current_match_id' => $this->service->current($customer)?->id,
            'current_room_id'  => $rooms->current($customer)?->id,
            'card_ranks'       => Card::RANKS,
            'card_suits'       => Card::SUITS,
            'card_deck'        => TienLenDeckService::summary(TienLenDeckService::selectedFor((string) $customer->id)),
        ]]);
    }

    /**
     * GET /api/games/tien-len/card-decks — mọi bộ bài (đều miễn phí) + bộ khách đang dùng.
     */
    public function cardDecks(Request $request): JsonResponse
    {
        $selected = TienLenDeckService::selectedFor((string) $request->user()->id);

        return response()->json(['data' => ['selected' => $selected, 'decks' => TienLenDeckService::catalog($selected)]]);
    }

    /**
     * PUT /api/games/tien-len/card-decks/selected — chọn bộ bài. Body: deck (key). Đổi được cả khi đang chơi, lần lấy state sau dùng ảnh bộ mới.
     */
    public function selectCardDeck(Request $request): JsonResponse
    {
        $data = $request->validate(['deck' => 'required|string|in:' . implode(',', TienLenDeckService::keys())], [
            'deck.in' => 'Bộ bài không tồn tại.',
        ]);

        TienLenDeckService::select((string) $request->user()->id, $data['deck']);

        return $this->cardDecks($request);
    }

    /**
     * POST /api/games/tien-len/matches — chơi với bot. Body: bots (1..3). Đang có ván với bot dở thì ván đó bị xử thua (0 điểm).
     */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['bots' => 'required|integer|min:1|max:3']);

        return $this->run($request, fn () => $this->service->start($request->user(), (int) $data['bots']), 201);
    }

    /**
     * GET /api/games/tien-len/matches/current — ván với bot đang dở (data = null nếu không có).
     */
    public function current(Request $request): JsonResponse
    {
        $match = $this->service->current($request->user());

        return $match ? $this->show($request, $match->id) : response()->json(['data' => null]);
    }

    /**
     * GET /api/games/tien-len/matches?mode=bot|room&page= — lịch sử ván của khách (cả ván với bot và ván phòng).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['mode' => 'sometimes|nullable|in:bot,room']);

        $page = TienLenMatchPlayer::where('customer_id', $request->user()->id)
            ->whereHas('match', fn ($q) => $q->where('status', '!=', 'playing')
                ->when($request->input('mode') === 'bot', fn ($m) => $m->whereNull('room_id'))
                ->when($request->input('mode') === 'room', fn ($m) => $m->whereNotNull('room_id')))
            ->with('match:id,room_id,player_count,status,instant_win,created_at,finished_at')
            ->latest('id')->paginate(20);

        return response()->json([
            'data' => $page->getCollection()->map(fn (TienLenMatchPlayer $p) => [
                'id'            => $p->match_id,
                'mode'          => $p->match->room_id ? 'room' : 'bot',
                'player_count'  => $p->match->player_count,
                'status'        => $p->match->status,
                'player_status' => $p->status,
                'rank'          => $p->rank,
                'rank_label'    => $p->rank ? TienLenService::rankLabel($p->rank, $p->match->player_count) : null,
                'score'         => $p->score,
                'reward_points' => $p->reward_points,
                'instant_win'   => $p->match->instant_win,
                'created_at'    => $p->match->created_at?->toDateTimeString(),
                'finished_at'   => $p->match->finished_at?->toDateTimeString(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * GET /api/games/tien-len/matches/{id} — trạng thái ván (có người quá giờ lượt thì BE xử lý trước, events có nội dung).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->run($request, fn () => $this->service->sync($request->user(), $id));
    }

    /**
     * GET /api/games/tien-len/matches/{id}/events?after= — các sự kiện có event_no > after (để người khác trong phòng chạy lại hiệu ứng).
     */
    public function events(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['after' => 'sometimes|integer|min:0']);

        return $this->run($request, function () use ($request, $id, $data) {
            $match = $this->service->sync($request->user(), $id)['match'];

            return ['match' => $match, 'events' => $this->service->eventsAfter($match, (int) ($data['after'] ?? 0))];
        });
    }

    /**
     * POST /api/games/tien-len/matches/{id}/play — Body: cards (mảng id lá), seq (seq của state đang hiển thị).
     * Phòng nhiều người: khi state.can_chop_out_of_turn = true được gửi 4 đôi thông dù chưa tới lượt (chặt ngoài lượt).
     */
    public function play(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'cards'   => 'required|array|min:1|max:13',
            'cards.*' => 'required|integer|distinct|min:0|max:51',
            'seq'     => 'required|integer|min:0',
        ]);

        return $this->run($request, fn () => $this->service->play($request->user(), $id, array_map('intval', $data['cards']), (int) $data['seq']));
    }

    /**
     * POST /api/games/tien-len/matches/{id}/pass — Body: seq. Ván với bot: khi state.interrupt = true thì pass nghĩa là không chặt ngoài lượt.
     */
    public function pass(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['seq' => 'required|integer|min:0']);

        return $this->run($request, fn () => $this->service->pass($request->user(), $id, (int) $data['seq']));
    }

    /**
     * GET /api/games/tien-len/matches/{id}/hints — các bộ đánh được lúc này (nhỏ trước, hàng chặt sau cùng), tối đa 20.
     */
    public function hints(Request $request, string $id): JsonResponse
    {
        try {
            $match = $this->service->find($request->user(), $id);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        }

        $seat = $match->seatOf((string) $request->user()->id);

        return response()->json(['data' => $seat === null ? [] : array_map(fn ($c) => TienLenService::comboPayload($c), $this->service->hints($match, $seat))]);
    }

    /**
     * POST /api/games/tien-len/matches/{id}/forfeit — bỏ ván với bot: xử thua, 0 điểm. Ván phòng: rời phòng (bot đánh thay, 0 điểm).
     */
    public function forfeit(Request $request, string $id, TienLenRoomService $rooms): JsonResponse
    {
        try {
            $match = $this->service->find($request->user(), $id);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        }

        if ($match->room_id) {
            return $this->run($request, function () use ($request, $rooms, $match) {
                $rooms->leave($request->user(), $match->room_id);

                return ['match' => $match->fresh(), 'events' => []];
            });
        }

        return $this->run($request, fn () => $this->service->forfeit($request->user(), $id));
    }

    private function run(Request $request, \Closure $fn, int $status = 200): JsonResponse
    {
        $customerId = (string) $request->user()->id;

        try {
            ['match' => $match, 'events' => $events] = $fn();
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (StaleMatchException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data'    => $e->match ? ['state' => TienLenService::statePayload($e->match, $e->match->seatOf($customerId)), 'events' => $e->events] : null,
            ], 409);
        } catch (GameException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['state' => TienLenService::statePayload($match, $match->seatOf($customerId)), 'events' => $events]], $status);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Không tìm thấy ván chơi.'], 404);
    }
}

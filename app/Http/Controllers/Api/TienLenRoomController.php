<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Games\TienLen\GameException;
use App\Http\Controllers\Controller;
use App\Models\TienLenRoom;
use App\Services\TienLenRoomService;
use App\Services\TienLenService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phòng Tiến lên nhiều người (khách đã đăng nhập). Luồng: tạo phòng / vào bằng mã / ghép nhanh → chủ phòng mời bot (tuỳ chọn) → mọi người
 * sẵn sàng → chủ phòng bắt đầu → đánh bằng /api/games/tien-len/matches/{match_id}/play|pass như ván với bot.
 * Realtime: nghe kênh private-tien-len.room.{room_id} (sự kiện "updated", xác thực ở POST /api/broadcasting/auth); nhận được thì gọi
 * GET /rooms/{id}?after={event_no cuối đã chạy}. Không có websocket thì gọi định kỳ 1–2 giây. Xem App\Services\TienLenRoomService.
 */
class TienLenRoomController extends Controller
{
    public function __construct(private readonly TienLenRoomService $rooms)
    {
    }

    /**
     * GET /api/games/tien-len/rooms?max_players= — phòng công khai đang chờ còn chỗ.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['max_players' => 'sometimes|nullable|integer|min:2|max:4']);

        return response()->json(['data' => $this->rooms->publicRooms($data['max_players'] ?? null)->map(fn (TienLenRoom $r) => [
            'id'          => $r->id,
            'code'        => $r->code,
            'max_players' => $r->max_players,
            'players'     => $r->seats_count,
            'host'        => $r->seats->firstWhere('customer_id', $r->host_customer_id)?->customer?->fullname,
            'created_at'  => $r->created_at?->toDateTimeString(),
        ])->values()]);
    }

    /**
     * POST /api/games/tien-len/rooms — Body: max_players (2..4, mặc định 4), is_public (mặc định false).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['max_players' => 'sometimes|integer|min:2|max:4', 'is_public' => 'sometimes|boolean']);

        return $this->respond($request, fn () => $this->rooms->create($request->user(), (int) ($data['max_players'] ?? 4), (bool) ($data['is_public'] ?? false)), 201);
    }

    /**
     * POST /api/games/tien-len/rooms/join — Body: code.
     */
    public function join(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:8']);

        return $this->respond($request, fn () => $this->rooms->joinByCode($request->user(), $data['code']));
    }

    /**
     * POST /api/games/tien-len/rooms/quick-join — Body: max_players (tuỳ chọn). Vào phòng công khai còn chỗ, không có thì tạo mới.
     */
    public function quickJoin(Request $request): JsonResponse
    {
        $data = $request->validate(['max_players' => 'sometimes|nullable|integer|min:2|max:4']);

        return $this->respond($request, fn () => $this->rooms->quickJoin($request->user(), isset($data['max_players']) ? (int) $data['max_players'] : null));
    }

    /**
     * GET /api/games/tien-len/rooms/current — phòng khách đang ở (data = null nếu không có).
     */
    public function current(Request $request): JsonResponse
    {
        $room = $this->rooms->current($request->user());

        return $room ? $this->show($request, $room->id) : response()->json(['data' => null]);
    }

    /**
     * GET /api/games/tien-len/rooms/{id}?after= — phòng + ván hiện tại (hoặc ván vừa xong) theo góc nhìn khách + sự kiện ván có event_no > after.
     * Gọi định kỳ cũng là báo "tôi còn ở đây" (phòng chờ bỏ ghế người im lặng quá 90 giây).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['after' => 'sometimes|integer|min:0']);

        try {
            ['room' => $room, 'match' => $match, 'events' => $events] = $this->rooms->view($request->user(), $id, (int) ($data['after'] ?? 0));
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Bạn không ở trong phòng này.'], 404);
        } catch (GameException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $cid = (string) $request->user()->id;

        return response()->json(['data' => [
            'room'   => TienLenRoomService::payload($room, $cid),
            'match'  => $match ? TienLenService::statePayload($match, $match->seatOf($cid)) : null,
            'events' => $events,
        ]]);
    }

    /**
     * POST /api/games/tien-len/rooms/{id}/ready — Body: ready (true/false).
     */
    public function ready(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['ready' => 'required|boolean']);

        return $this->respond($request, fn () => $this->rooms->setReady($request->user(), $id, (bool) $data['ready']));
    }

    /**
     * POST /api/games/tien-len/rooms/{id}/bots — chủ phòng mời bot vào ghế trống. Body: count (mặc định 1).
     */
    public function addBots(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['count' => 'sometimes|integer|min:1|max:3']);

        return $this->respond($request, fn () => $this->rooms->addBots($request->user(), $id, (int) ($data['count'] ?? 1)));
    }

    /**
     * DELETE /api/games/tien-len/rooms/{id}/seats/{seat} — chủ phòng mời bot / người ra khỏi ghế (khi phòng đang chờ).
     */
    public function removeSeat(Request $request, string $id, int $seat): JsonResponse
    {
        return $this->respond($request, fn () => $this->rooms->removeSeat($request->user(), $id, $seat));
    }

    /**
     * POST /api/games/tien-len/rooms/{id}/start — chủ phòng bắt đầu ván. Trả { room, match, events } như GET phòng.
     */
    public function start(Request $request, string $id): JsonResponse
    {
        $cid = (string) $request->user()->id;

        try {
            ['room' => $room, 'match' => $match, 'events' => $events] = $this->rooms->start($request->user(), $id);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Bạn không ở trong phòng này.'], 404);
        } catch (GameException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => [
            'room'   => TienLenRoomService::payload($room, $cid),
            'match'  => TienLenService::statePayload($match, $match->seatOf($cid)),
            'events' => $events,
        ]], 201);
    }

    /**
     * POST /api/games/tien-len/rooms/{id}/leave — rời phòng. Đang chơi thì bot đánh nốt ghế của khách (0 điểm).
     */
    public function leave(Request $request, string $id): JsonResponse
    {
        try {
            $this->rooms->leave($request->user(), $id);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Bạn không ở trong phòng này.'], 404);
        }

        return response()->json(['data' => null, 'message' => 'Đã rời phòng.']);
    }

    private function respond(Request $request, \Closure $fn, int $status = 200): JsonResponse
    {
        try {
            $room = $fn();
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Bạn không ở trong phòng này.'], 404);
        } catch (GameException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['room' => TienLenRoomService::payload($room, (string) $request->user()->id)]], $status);
    }
}

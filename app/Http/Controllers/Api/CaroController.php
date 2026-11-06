<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaroInvite;
use App\Models\CaroMatch;
use App\Models\CaroReward;
use App\Models\CaroSetting;
use App\Models\Customer;
use App\Services\CaroService;
use App\Services\ScratchCardService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

// Caro trực tuyến (người với người, 2 hoặc 3 người) cho khách đã đăng nhập: ghép ngẫu nhiên hoặc mời bạn. App chỉ vẽ bàn cờ — lượt đi, thắng thua, hết giờ và voucher đều do BE quyết (xem CaroService).
// Trạng thái được đẩy qua WS trên kênh cá nhân (caro.matched / caro.updated / caro.invited / caro.invite_updated); mất WS thì app hỏi lại bằng GET.
class CaroController extends Controller
{
    public function __construct(private readonly CaroService $service) {}

    /**
     * GET /api/games/caro — luật chơi, tiến độ nhận voucher, số người đang online, trận đang dở, mã người chơi của mình (để bạn bè mời
     * đích danh) và các lời mời đến (được mời theo SĐT / mã người chơi, còn hiệu lực).
     */
    public function index(Request $request): JsonResponse
    {
        $setting  = CaroSetting::current();
        $customer = $this->customer($request);
        $id       = (string) $customer->id;
        $active   = $this->service->activeMatch($id);

        return response()->json(['data' => [
            'is_enabled'         => $setting->is_enabled,
            'rules'              => [
                'board_size'                 => $setting->board_size,
                'win_length'                 => $setting->win_length,
                'block_two_ends'             => $setting->block_two_ends,
                'turn_seconds'               => $setting->turn_seconds,
                'three_player_enabled'       => $setting->three_player_enabled,
                'cycle_wins'                 => $setting->cycleLength(),
                'daily_counted_wins_cap'     => $setting->daily_counted_wins_cap,
                'streak_days'                => $setting->streak_days ?: null,
                'invite_bonus_enabled'       => $setting->invite_bonus_enabled,
                'max_caro_coupons_per_order' => $setting->max_caro_coupons_per_order,
                'match_wait_seconds'         => $setting->match_wait_seconds,
                'invite_enabled'             => $setting->invite_enabled,
                'invite_expire_minutes'      => $setting->invite_expire_minutes,
            ],
            'progress'           => $this->service->progress($id),
            'online'             => $this->service->onlineCount(),
            'active_match'       => $active ? $this->service->state($active, $id) : null,
            'player_code'        => $this->service->playerCode($id),
            'incoming_invites'   => $this->incomingPayload($customer),
        ]]);
    }

    /**
     * POST|GET /api/games/caro/queue — vào hàng chờ, sau đó app gọi lại mỗi 2-3 giây (giữ chỗ trong hàng) cho tới khi status = matched.
     * Chờ đủ match_wait_seconds mà chưa có ai thì suggest_invite = true → app gợi ý mời bạn (POST /invites), vẫn tiếp tục chờ.
     * players (2 | 3, tuỳ chọn): chờ trận mấy người — bỏ trống khi hỏi lại thì giữ chế độ đang chờ (mặc định 2).
     */
    public function seek(Request $request): JsonResponse
    {
        $data     = $request->validate(['players' => 'nullable|integer|in:2,3']);
        $customer = $this->customer($request);
        $result   = $this->service->seek($customer, isset($data['players']) ? (int) $data['players'] : null);

        if ($result['status'] === 'matched') {
            return response()->json(['data' => ['status' => 'matched', 'match' => $this->service->state($result['match'], (string) $customer->id)]]);
        }

        return response()->json(['data' => [
            ...$result,
            'match_wait_seconds' => CaroSetting::current()->match_wait_seconds,
            'online'             => $this->service->onlineCount(),
        ]]);
    }

    /**
     * DELETE /api/games/caro/queue — rời hàng chờ.
     */
    public function leave(Request $request): JsonResponse
    {
        $this->service->leaveQueue($this->customer($request));

        return response()->json(['success' => true]);
    }

    /**
     * GET /api/games/caro/invites — lời mời mình đang gửi (outgoing, tối đa 1) và lời mời đến (incoming: được mời theo SĐT / mã người chơi).
     */
    public function invites(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $outgoing = $this->service->outgoingInvite($customer);

        return response()->json(['data' => [
            'outgoing' => $outgoing ? $this->service->invitePayload($outgoing, (string) $customer->id) : null,
            'incoming' => $this->incomingPayload($customer),
        ]]);
    }

    /**
     * POST /api/games/caro/invites — Body (đều tuỳ chọn): players (2 | 3 — bỏ trống thì giữ số người của lời mời đang có, mặc định 2),
     * phone hoặc player_code (mã người chơi của bạn, xem player_code ở GET /api/games/caro). Tạo (hoặc lấy lại) mã mời kèm share_url /
     * share_text để gửi cho bạn; có phone / player_code thì báo thêm cho khách đó (push caro_invite + WS caro.invited, hiện trong lời mời
     * đến của họ). Lời mời 3 người: gọi 2 lần cho 2 bạn, mã không đổi. Bạn nhập mã / bấm thông báo để vào trận.
     */
    public function invite(Request $request): JsonResponse
    {
        $data     = $request->validate([
            'players'     => 'nullable|integer|in:2,3',
            'phone'       => 'nullable|string|max:20',
            'player_code' => 'nullable|string|max:12',
        ]);
        $customer   = $this->customer($request);
        $phone      = trim((string) ($data['phone'] ?? ''));
        $playerCode = trim((string) ($data['player_code'] ?? ''));
        $invitee    = null;

        if ($phone !== '' || $playerCode !== '') {
            // Chặn dò SĐT / mã nào có tài khoản: tối đa 10 lần tìm / 10 phút mỗi khách.
            $key   = 'caro-invite-phone:' . $customer->id;
            $field = $phone !== '' ? 'phone' : 'player_code';
            if (RateLimiter::tooManyAttempts($key, 10)) {
                throw ValidationException::withMessages([$field => ['Bạn đã mời đích danh quá nhiều lần, thử lại sau ' . ceil(RateLimiter::availableIn($key) / 60) . ' phút hoặc gửi mã mời cho bạn.']])->status(429);
            }
            RateLimiter::hit($key, 600);

            $invitee = $phone !== ''
                ? $this->service->inviteeByPhone($customer, $phone)
                : $this->service->inviteeByPlayerCode($customer, $playerCode);
        }

        $invite = $this->service->createInvite($customer, isset($data['players']) ? (int) $data['players'] : null);
        if ($invitee) {
            $this->service->notifyInvitee($invite, $customer, $invitee);
        }

        return response()->json(['data' => $this->service->invitePayload($invite, (string) $customer->id) + [
            'notified' => $invitee ? ['name' => $this->service->publicName($invitee)] : null,
        ]], 201);
    }

    /**
     * GET /api/games/caro/invites/{code} — người mời (và bạn đang trong phòng chờ 3 người) hỏi lại tới khi status = accepted (kèm match);
     * người nhận xem trước khi bấm nhận (player_count, guests, slots_left).
     */
    public function showInvite(Request $request, string $code): JsonResponse
    {
        return $this->respondInvite($request, fn (Customer $customer) => $this->service->findInvite($code, $customer));
    }

    /**
     * POST /api/games/caro/invites/{code}/accept — nhận lời mời. Lời mời 2 người: trả trận vừa tạo (như trước). Lời mời 3 người: trả lời mời
     * (joined = true); match có khi bạn là người thứ 3 vào phòng, chưa đủ thì chờ WS caro.matched hoặc hỏi lại GET /invites/{code}.
     */
    public function acceptInvite(Request $request, string $code): JsonResponse
    {
        $customer = $this->customer($request);

        try {
            $invite = $this->service->acceptInvite($code, $customer);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Không tìm thấy lời mời.'], 404);
        }

        if ($invite->player_count === 2) {
            return $this->respond($request, fn () => CaroMatch::findOrFail($invite->match_id));
        }

        return response()->json(['data' => $this->service->invitePayload($invite, (string) $customer->id)]);
    }

    /**
     * DELETE /api/games/caro/invites/{code} — người mời huỷ lời mời; bạn đã vào phòng chờ 3 người (chưa đủ người) thì rời phòng.
     */
    public function cancelInvite(Request $request, string $code): JsonResponse
    {
        return $this->respondInvite($request, fn (Customer $customer) => $this->service->cancelInvite($code, $customer));
    }

    /**
     * GET /api/games/caro/matches/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->respond($request, fn (Customer $customer) => $this->service->find($id, $customer));
    }

    /**
     * POST /api/games/caro/matches/{id}/move — Body: x (cột), y (hàng), 0 .. board_size - 1. Đánh với máy thì response đã gồm nước của máy.
     */
    public function move(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'x' => 'required|integer|min:0',
            'y' => 'required|integer|min:0',
        ]);

        return $this->respond($request, fn (Customer $customer) => $this->service->move($id, $customer, (int) $data['x'], (int) $data['y']));
    }

    /**
     * POST /api/games/caro/matches/{id}/resign — đầu hàng (trận 3 người: bị loại, 2 người còn lại đánh tiếp; vẫn xem được trận).
     */
    public function resign(Request $request, string $id): JsonResponse
    {
        return $this->respond($request, fn (Customer $customer) => $this->service->resign($id, $customer));
    }

    /**
     * GET /api/games/caro/rewards?page= — các voucher đã nhận từ caro.
     */
    public function rewards(Request $request): JsonResponse
    {
        $page = CaroReward::where('customer_id', $this->customer($request)->id)
            ->with('coupon')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => $page->getCollection()->map(fn (CaroReward $r) => [
                'id'         => $r->id,
                'label'      => $r->prize_label,
                'match_id'   => $r->match_id,
                'created_at' => $r->created_at?->toDateTimeString(),
                'coupon'     => ScratchCardService::couponPayload($r->coupon),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    // Route đã qua customer.active nên user() luôn là Customer
    private function customer(Request $request): Customer
    {
        return $request->user();
    }

    private function incomingPayload(Customer $customer): array
    {
        return $this->service->incomingInvites($customer)
            ->map(fn (CaroInvite $invite) => $this->service->invitePayload($invite, (string) $customer->id))
            ->values()
            ->all();
    }

    private function respondInvite(Request $request, callable $load): JsonResponse
    {
        $customer = $this->customer($request);

        try {
            $invite = $load($customer);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Không tìm thấy lời mời.'], 404);
        }

        return response()->json(['data' => $this->service->invitePayload($invite, (string) $customer->id)]);
    }

    private function respond(Request $request, callable $load): JsonResponse
    {
        $customer = $this->customer($request);

        try {
            $match = $load($customer);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Không tìm thấy trận đấu.'], 404);
        }

        return response()->json(['data' => $this->service->state($match, (string) $customer->id)]);
    }
}

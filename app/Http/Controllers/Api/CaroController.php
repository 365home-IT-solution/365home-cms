<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaroReward;
use App\Models\CaroSetting;
use App\Models\Customer;
use App\Services\CaroService;
use App\Services\ScratchCardService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Caro trực tuyến (người với người) cho khách đã đăng nhập: ghép ngẫu nhiên hoặc mời bạn. App chỉ vẽ bàn cờ — lượt đi, thắng thua, hết giờ và voucher đều do BE quyết (xem CaroService).
// Trạng thái được đẩy qua WS trên kênh cá nhân (caro.matched / caro.updated / caro.invite_updated); mất WS thì app hỏi lại bằng GET.
class CaroController extends Controller
{
    public function __construct(private readonly CaroService $service) {}

    /**
     * GET /api/games/caro — luật chơi, tiến độ nhận voucher, số người đang online, trận đang dở, số lời mời đang chờ mình.
     */
    public function index(Request $request): JsonResponse
    {
        $setting  = CaroSetting::current();
        $customer = $this->customer($request);
        $active   = $this->service->activeMatch((string) $customer->id);

        return response()->json(['data' => [
            'is_enabled'         => $setting->is_enabled,
            'rules'              => [
                'board_size'                 => $setting->board_size,
                'win_length'                 => $setting->win_length,
                'block_two_ends'             => $setting->block_two_ends,
                'turn_seconds'               => $setting->turn_seconds,
                'cycle_wins'                 => $setting->cycleLength(),
                'daily_counted_wins_cap'     => $setting->daily_counted_wins_cap,
                'streak_days'                => $setting->streak_days ?: null,
                'invite_bonus_enabled'       => $setting->invite_bonus_enabled,
                'max_caro_coupons_per_order' => $setting->max_caro_coupons_per_order,
                'match_wait_seconds'         => $setting->match_wait_seconds,
                'invite_enabled'             => $setting->invite_enabled,
                'invite_expire_minutes'      => $setting->invite_expire_minutes,
            ],
            'progress'           => $this->service->progress((string) $customer->id),
            'online'             => $this->service->onlineCount(),
            'active_match'       => $active ? $this->service->state($active, (string) $customer->id) : null,
        ]]);
    }

    /**
     * POST|GET /api/games/caro/queue — vào hàng chờ, sau đó app gọi lại mỗi 2-3 giây (giữ chỗ trong hàng) cho tới khi status = matched.
     * Chờ đủ match_wait_seconds mà chưa có ai thì suggest_invite = true → app gợi ý mời bạn (POST /invites), vẫn tiếp tục chờ.
     */
    public function seek(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $result   = $this->service->seek($customer);

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
     * GET /api/games/caro/invites — lời mời mình đang gửi (outgoing, tối đa 1).
     */
    public function invites(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $outgoing = $this->service->outgoingInvite($customer);

        return response()->json(['data' => [
            'outgoing' => $outgoing ? $this->service->invitePayload($outgoing, (string) $customer->id) : null,
        ]]);
    }

    /**
     * POST /api/games/caro/invites — tạo mã mời (kèm share_url / share_text) để gửi cho bạn; bạn nhập mã trên app để vào trận.
     */
    public function invite(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $invite   = $this->service->createInvite($customer);

        return response()->json(['data' => $this->service->invitePayload($invite, (string) $customer->id)], 201);
    }

    /**
     * GET /api/games/caro/invites/{code} — người mời hỏi lại tới khi status = accepted (kèm match); người nhận xem trước khi bấm nhận.
     */
    public function showInvite(Request $request, string $code): JsonResponse
    {
        return $this->respondInvite($request, fn (Customer $customer) => $this->service->findInvite($code, $customer));
    }

    /**
     * POST /api/games/caro/invites/{code}/accept — nhận lời mời, trả trận vừa tạo.
     */
    public function acceptInvite(Request $request, string $code): JsonResponse
    {
        return $this->respond($request, fn (Customer $customer) => $this->service->acceptInvite($code, $customer));
    }

    /**
     * DELETE /api/games/caro/invites/{code} — người mời huỷ lời mời.
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
     * POST /api/games/caro/matches/{id}/resign — đầu hàng.
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

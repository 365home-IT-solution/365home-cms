<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GamePointReward;
use App\Models\GamePointTransaction;
use App\Services\GamePointService;
use App\Services\ScratchCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Điểm game của khách đã đăng nhập: số dư, lịch sử, danh mục quà và đổi điểm lấy voucher (xem App\Services\GamePointService).
class GamePointController extends Controller
{
    public function __construct(private readonly GamePointService $points)
    {
    }

    /**
     * GET /api/games/points?type=earn|redeem|adjust&page= — số dư + lịch sử cộng/trừ.
     */
    public function me(Request $request): JsonResponse
    {
        $request->validate(['type' => 'sometimes|nullable|in:earn,redeem,adjust']);
        $customerId = (string) $request->user()->id;
        $wallet     = $this->points->wallet($customerId);

        $page = GamePointTransaction::where('customer_id', $customerId)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->with('coupon')->latest('id')->paginate(20);

        return response()->json([
            'data' => [
                'balance'        => $wallet->balance,
                'total_earned'   => $wallet->total_earned,
                'total_redeemed' => $wallet->total_redeemed,
                'transactions'   => $page->getCollection()->map(fn (GamePointTransaction $t) => GamePointService::transactionPayload($t))->values(),
            ],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * GET /api/games/points/rewards — quà đang mở đổi (kèm can_redeem theo số dư hiện tại).
     */
    public function rewards(Request $request): JsonResponse
    {
        $balance = $this->points->balance((string) $request->user()->id);

        return response()->json([
            'data'    => GamePointReward::where('is_active', true)->orderBy('sort_order')->orderBy('points_cost')->get()
                ->map(fn (GamePointReward $r) => GamePointService::rewardPayload($r, $balance))->values(),
            'balance' => $balance,
        ]);
    }

    /**
     * POST /api/games/points/rewards/{id}/redeem — đổi điểm lấy voucher (vào GET /api/coupons/mine, source = game_points). Lỗi 422 kèm message.
     */
    public function redeem(Request $request, int $id): JsonResponse
    {
        ['transaction' => $t, 'coupon' => $coupon] = $this->points->redeem($request->user(), $id);

        return response()->json(['data' => [
            'balance' => $t->balance_after,
            'spent'   => -$t->amount,
            'coupon'  => ScratchCardService::couponPayload($coupon),
        ]], 201);
    }
}

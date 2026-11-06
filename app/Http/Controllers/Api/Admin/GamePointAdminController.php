<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\GamePointReward;
use App\Models\GamePointTransaction;
use App\Services\GamePointService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Quản trị điểm game (chỉ Super Admin) — cùng dữ liệu với Filament "Điểm game — Quà đổi" / "Điểm game — Lịch sử".
 *
 *  - GET|POST /api/admin/games/points/rewards · PUT|DELETE .../rewards/{id}   danh mục quà; xoá quà đã có người đổi ⇒ chỉ tắt
 *  - GET /api/admin/games/points/transactions?customer_id=&type=&source=&from=&to=&per_page=   lịch sử cộng/trừ
 *  - GET /api/admin/games/points/customers/{id}                                ví của 1 khách
 *  - POST /api/admin/games/points/adjust   Body: customer_id, amount (+/−), note — cộng/trừ tay
 */
class GamePointAdminController extends Controller
{
    public function __construct(private readonly GamePointService $points)
    {
    }

    public function rewards(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        return response()->json(['data' => GamePointReward::orderBy('sort_order')->orderBy('points_cost')->get()]);
    }

    public function storeReward(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        return response()->json(['data' => GamePointReward::create($this->validatedReward($request))->fresh()], 201);
    }

    public function updateReward(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);

        $reward = GamePointReward::findOrFail($id);
        $reward->update($this->validatedReward($request, $reward));

        return response()->json(['data' => $reward->fresh()]);
    }

    public function destroyReward(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuper($request);

        $reward = GamePointReward::findOrFail($id);
        if ($reward->redeemed_count > 0) {
            $reward->update(['is_active' => false]);

            return response()->json(['message' => 'Quà đã có người đổi nên chỉ được tắt, không xoá.', 'data' => $reward->fresh()]);
        }

        $reward->delete();

        return response()->json(['message' => 'Đã xoá quà.']);
    }

    public function transactions(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        $request->validate([
            'customer_id' => 'sometimes|nullable|string',
            'type'        => 'sometimes|nullable|in:earn,redeem,adjust',
            'source'      => 'sometimes|nullable|in:tien_len,reward,admin',
            'from'        => 'sometimes|nullable|date',
            'to'          => 'sometimes|nullable|date',
            'per_page'    => 'sometimes|integer|min:1|max:100',
        ]);

        $page = GamePointTransaction::with(['customer:id,fullname,phone', 'coupon', 'creator:id,fullname'])
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->input('customer_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->input('source')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', Carbon::parse($request->input('from'))->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', Carbon::parse($request->input('to'))->endOfDay()))
            ->latest('id')
            ->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn (GamePointTransaction $t) => GamePointService::transactionPayload($t) + [
                'customer'   => $t->customer ? ['id' => $t->customer->id, 'fullname' => $t->customer->fullname, 'phone' => $t->customer->phone] : null,
                'created_by' => $t->creator?->fullname,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function customer(Request $request, string $id): JsonResponse
    {
        $this->authorizeSuper($request);

        $customer = Customer::findOrFail($id);

        return response()->json(['data' => ['customer' => ['id' => $customer->id, 'fullname' => $customer->fullname, 'phone' => $customer->phone]]
            + $this->points->wallet((string) $customer->id)->only(['balance', 'total_earned', 'total_redeemed'])]);
    }

    public function adjust(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        $data = $request->validate([
            'customer_id' => 'required|string|exists:customers,id',
            'amount'      => 'required|integer|not_in:0|min:-1000000|max:1000000',
            'note'        => 'required|string|max:255',
        ]);

        $t = $this->points->adjust($data['customer_id'], (int) $data['amount'], $data['note'], $request->user());

        return response()->json(['data' => GamePointService::transactionPayload($t)], 201);
    }

    private function validatedReward(Request $request, ?GamePointReward $reward = null): array
    {
        $data = $request->validate(GamePointReward::rules($reward !== null));

        $type  = $data['coupon_type'] ?? $reward?->coupon_type;
        $value = $data['coupon_value'] ?? $reward?->coupon_value;
        if ($type === 'percentage' && (float) $value > 100) {
            throw ValidationException::withMessages(['coupon_value' => ['Giảm theo phần trăm không được quá 100.']]);
        }

        return $data;
    }

    private function authorizeSuper(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ScratchCard;
use App\Services\ScratchCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Thẻ cào của khách đã đăng nhập. App chỉ chạy hiệu ứng cào — giải do BE bốc khi gọi scratch (xem ScratchCardService).
class ScratchCardController extends Controller
{
    /**
     * GET /api/games/scratch-cards?status=available|scratched|expired|voided&page=1
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => 'sometimes|nullable|in:available,scratched,expired,voided']);

        /** @var Customer $customer */
        $customer = $request->user();

        // Thẻ quá hạn mà job chưa kịp đánh dấu thì đánh dấu ngay để danh sách luôn đúng
        ScratchCard::where('customer_id', $customer->id)->where('status', ScratchCard::STATUS_AVAILABLE)
            ->where('expires_at', '<', now())->update(['status' => ScratchCard::STATUS_EXPIRED]);

        $page = ScratchCard::where('customer_id', $customer->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->with(['order.category', 'coupon'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data'            => $page->getCollection()->map(fn (ScratchCard $c) => ScratchCardService::cardPayload($c))->values(),
            'meta'            => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'available_count' => self::availableCount($customer),
        ]);
    }

    /**
     * POST /api/games/scratch-cards/{id}/scratch — không body. Gọi lại thẻ đã cào trả đúng kết quả cũ (already_claimed).
     */
    public function scratch(Request $request, string $id, ScratchCardService $service): JsonResponse
    {
        try {
            $result = $service->scratch($id, $request->user());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['message' => 'Thẻ cào không tồn tại.'], 404); // thẻ không có hoặc không phải của khách này
        }

        $card   = $result['card'];

        return response()->json(['data' => [
            'id'              => $card->id,
            'status'          => $card->status,
            'scratched_at'    => $card->scratched_at?->toDateTimeString(),
            'already_claimed' => $result['already_claimed'],
            'reward'          => ScratchCardService::rewardPayload($card),
        ]]);
    }

    public static function availableCount(Customer $customer): int
    {
        return ScratchCard::where('customer_id', $customer->id)->where('status', ScratchCard::STATUS_AVAILABLE)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))->count();
    }
}

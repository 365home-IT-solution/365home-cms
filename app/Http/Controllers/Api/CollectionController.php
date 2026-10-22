<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CollectionCampaign;
use App\Models\Customer;
use App\Services\CollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Sưu tầm mảnh ghép theo chi nhánh. Xem danh sách/chi tiết không cần đăng nhập (khách chưa đăng nhập thấy thể lệ, mọi mảnh chưa có);
// đổi thưởng cần đăng nhập. Xem App\Services\CollectionService.
class CollectionController extends Controller
{
    public function __construct(private readonly CollectionService $service) {}

    /** GET /api/games/collections */
    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer();

        return response()->json(['data' => $this->service->visibleCampaigns($customer)
            ->map(fn (CollectionCampaign $c) => $this->service->campaignPayload($c, $customer?->id))->values()]);
    }

    /** GET /api/games/collections/{id} */
    public function show(int $id): JsonResponse
    {
        $campaign = $this->service->visibleCampaigns($customer = $this->customer())->firstWhere('id', $id);

        abort_unless($campaign, 404, 'Chiến dịch không tồn tại.');

        return response()->json(['data' => $this->service->campaignPayload($campaign, $customer?->id)]);
    }

    /** POST /api/games/collections/{id}/redeem — không body. Gọi lại trả đúng kết quả cũ (already_claimed = true). */
    public function redeem(Request $request, int $id): JsonResponse
    {
        try {
            $result = $this->service->redeem($id, $request->user());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['message' => 'Chiến dịch không tồn tại.'], 404);
        }

        return response()->json([
            'data'            => $this->service->campaignPayload($result['campaign'], (string) $request->user()->id),
            'already_claimed' => $result['already_claimed'],
        ]);
    }

    private function customer(): ?Customer
    {
        $user = auth('sanctum')->user();

        return $user instanceof Customer ? $user : null;
    }
}

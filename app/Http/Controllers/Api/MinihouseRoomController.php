<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesProvince;
use App\Services\MinihouseRoomSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

// Phòng MiniHouse (thuê dài hạn) CÒN TRỐNG — chưa cho thuê — cho app/web khách. Logic lọc/geo xem
// App\Services\MinihouseRoomSearchService.
class MinihouseRoomController extends Controller
{
    use ResolvesProvince;

    public function __construct(private readonly MinihouseRoomSearchService $service) {}

    /**
     * GET /api/v1/minihouse/rooms
     * Query: q, building_id, province_id|province, lat+lng (+radius km, mặc định 10), min_price,
     *        max_price, min_area, max_area, amenity_ids[], sort (distance|latest|price_asc|
     *        price_desc|area_asc|area_desc — mặc định distance khi có lat/lng, ngược lại latest),
     *        page, per_page (mặc định 12, tối đa 100)
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service->search($request->all(), auth('sanctum')->user()));
    }

    /**
     * GET /api/v1/minihouse/rooms/nearby?lat=&lng=&radius=&limit=
     * Phòng trống gần vị trí khách, gần nhất trước. Không gửi lat/lng → phòng trống cùng tỉnh
     * (province_id/province hoặc tỉnh của tài khoản), không xác định được tỉnh → data rỗng.
     */
    public function nearby(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat'    => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng'    => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius' => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $lat = isset($validated['lat']) ? (float) $validated['lat'] : null;
        $lng = isset($validated['lng']) ? (float) $validated['lng'] : null;

        $rooms = $this->service->nearby(
            $lat,
            $lng,
            $this->resolveProvince($request),
            (float) ($validated['radius'] ?? MinihouseRoomSearchService::DEFAULT_NEARBY_RADIUS_KM),
            (int) ($validated['limit'] ?? 10),
            auth('sanctum')->user(),
        );

        return response()->json(['data' => $rooms]);
    }
}

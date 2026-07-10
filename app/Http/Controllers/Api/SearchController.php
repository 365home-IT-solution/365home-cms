<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesProvince;
use App\Services\RoomSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Product\App\Models\Product;

class SearchController extends Controller
{
    use ResolvesProvince;

    // ─── GET /v1/search/suggestions ─────────────────────────────────────────

    public function suggestions(): JsonResponse
    {
        $nearby = [
            'id'          => 'nearby',
            'name'        => 'Lân cận',
            'description' => 'Tìm phòng xung quanh bạn',
            'type'        => 'nearby',
            'icon'        => 'navigate-outline',
            'icon_color'  => '#4A90D9',
            'bg_color'    => '#E8F0FE',
            'query'       => null,
            'latitude'    => null,
            'longitude'   => null,
        ];

        $locations = Product::where('is_activated', true)
            ->where('is_in_stock', true)
            ->whereNotNull('address')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->selectRaw('address, MIN(latitude) as latitude, MIN(longitude) as longitude, COUNT(*) as room_count')
            ->groupBy('address')
            ->orderByDesc('room_count')
            ->limit(10)
            ->get()
            ->map(fn ($row, $index) => [
                'id'          => (string) ($index + 1),
                'name'        => $row->address,
                'description' => $row->room_count . ' phòng',
                'type'        => 'location',
                'icon'        => 'location-outline',
                'icon_color'  => '#4CAF50',
                'bg_color'    => '#E8F5E9',
                'query'       => $row->address,
                'latitude'    => (string) $row->latitude,
                'longitude'   => (string) $row->longitude,
            ])
            ->values();

        return response()->json(['data' => collect([$nearby])->merge($locations)->values()]);
    }

    // ─── GET /v1/search/branches ─────────────────────────────────────────────
    // Dùng cho "Xem tất cả" của block Gợi ý điểm đến (loại Chi nhánh) trên mobile —
    // trả về danh sách chi nhánh của khu vực thay vì danh sách phòng.
    public function branches(Request $request): JsonResponse
    {
        $province = $this->resolveProvince($request);

        if ($province === null) {
            return response()->json([
                'data' => [],
                'meta' => ['province_name' => null, 'total' => 0],
            ]);
        }

        $branches = $province->branches()
            ->where('status', true)
            ->with('category')
            ->get()
            ->filter(fn ($branch) => $branch->category && $branch->category->status)
            ->map(fn ($branch) => [
                'id'        => $branch->category->id,
                'name'      => $branch->category->name,
                'slug'      => $branch->category->slug,
                'image_url' => $branch->category->image
                    ? Storage::disk('public')->url($branch->category->image)
                    : null,
            ])
            ->values();

        return response()->json([
            'data' => $branches,
            'meta' => ['province_name' => $province->name, 'total' => $branches->count()],
        ]);
    }

    // ─── GET /v1/search ──────────────────────────────────────────────────────

    public function index(Request $request, RoomSearchService $searchService): JsonResponse
    {
        $result = $searchService->search($request->all(), auth('sanctum')->user());

        return response()->json($result);
    }

    // ─── GET /v1/search/locations ────────────────────────────────────────────

    public function locations(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        $results = Product::where('is_activated', true)
            ->where('is_in_stock', true)
            ->whereNotNull('address')
            ->where('address', 'like', "%{$q}%")
            ->orderBy('name')
            ->limit(10)
            ->get(['name', 'address', 'latitude', 'longitude'])
            ->map(fn ($p) => [
                'name'      => $p->address,
                'query'     => $p->address,
                'latitude'  => $p->latitude  ? (string) $p->latitude  : null,
                'longitude' => $p->longitude ? (string) $p->longitude : null,
            ])
            ->unique('name')
            ->values();

        return response()->json(['data' => $results]);
    }
}

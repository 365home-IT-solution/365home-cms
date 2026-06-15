<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\BuildsRoomCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomType;

class SearchController extends Controller
{
    use BuildsRoomCard;

    // ─── GET /v1/search/suggestions ─────────────────────────────────────────

    public function suggestions(): JsonResponse
    {
        $items = [
            [
                'id'          => '1',
                'name'        => 'Lân cận',
                'description' => 'Tìm xung quanh bạn',
                'type'        => 'nearby',
                'icon'        => 'navigate-outline',
                'icon_color'  => '#4A90D9',
                'bg_color'    => '#E8F0FE',
                'query'       => null,
                'latitude'    => null,
                'longitude'   => null,
            ],
            [
                'id'          => '2',
                'name'        => 'Đà Nẵng, Đà Nẵng',
                'description' => 'Điểm đến có bãi biển được ưa chuộng',
                'type'        => 'location',
                'icon'        => 'umbrella-outline',
                'icon_color'  => '#4CAF50',
                'bg_color'    => '#E8F5E9',
                'query'       => 'Đà Nẵng',
                'latitude'    => '16.0544',
                'longitude'   => '108.2022',
            ],
            [
                'id'          => '3',
                'name'        => 'Hội An, Quảng Nam',
                'description' => 'Phố cổ di sản thế giới',
                'type'        => 'location',
                'icon'        => 'business-outline',
                'icon_color'  => '#FF9800',
                'bg_color'    => '#FFF3E0',
                'query'       => 'Hội An',
                'latitude'    => '15.8801',
                'longitude'   => '108.3380',
            ],
            [
                'id'          => '4',
                'name'        => 'Hà Nội',
                'description' => 'Thủ đô nghìn năm văn hiến',
                'type'        => 'location',
                'icon'        => 'home-outline',
                'icon_color'  => '#9C27B0',
                'bg_color'    => '#F3E5F5',
                'query'       => 'Hà Nội',
                'latitude'    => '21.0285',
                'longitude'   => '105.8542',
            ],
            [
                'id'          => '5',
                'name'        => 'TP. Hồ Chí Minh',
                'description' => 'Thành phố năng động phía Nam',
                'type'        => 'location',
                'icon'        => 'storefront-outline',
                'icon_color'  => '#F44336',
                'bg_color'    => '#FFEBEE',
                'query'       => 'Hồ Chí Minh',
                'latitude'    => '10.8231',
                'longitude'   => '106.6297',
            ],
        ];

        return response()->json(['data' => $items]);
    }

    // ─── GET /v1/search ──────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category'  => ['nullable', 'string'],
            'q'         => ['nullable', 'string', 'max:255'],
            'lat'       => ['nullable', 'numeric', 'between:-90,90'],
            'lng'       => ['nullable', 'numeric', 'between:-180,180'],
            'radius'    => ['nullable', 'numeric', 'min:0'],
            'time_type' => ['nullable', Rule::in(['slot', 'day', 'month'])],
            'date'      => ['nullable', 'date_format:Y-m-d', 'required_if:time_type,slot'],
            'time_from' => ['nullable', 'date_format:H:i'],
            'time_to'   => ['nullable', 'date_format:H:i'],
            'from'      => ['nullable', 'date_format:Y-m-d', 'required_if:time_type,day'],
            'to'        => ['nullable', 'date_format:Y-m-d', 'required_if:time_type,day', 'after_or_equal:from'],
            'month'     => ['nullable', 'integer', 'between:1,12', 'required_if:time_type,month'],
            'year'      => ['nullable', 'integer', 'min:2020', 'required_if:time_type,month'],
            'guests'    => ['nullable', 'integer', 'min:1'],
            'page'      => ['nullable', 'integer', 'min:1'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Product::where('is_activated', true)
            ->where('is_in_stock', true)
            ->with(['roomTimeSlots.timeSlot', 'media', 'roomType']);

        // Filter by room_type_slug
        if (! empty($validated['category'])) {
            $roomType = RoomType::where('slug', $validated['category'])->first();
            if ($roomType) {
                $query->where('room_type_id', $roomType->id);
            }
        }

        // Keyword search
        if (! empty($validated['q'])) {
            $q = $validated['q'];
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('address', 'like', "%{$q}%");
            });
        }

        // Geo search (Haversine — requires lat & lng together)
        if (isset($validated['lat'], $validated['lng'])) {
            $lat    = (float) $validated['lat'];
            $lng    = (float) $validated['lng'];
            $radius = (float) ($validated['radius'] ?? 10);

            $query->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->selectRaw(
                    '*, ( 6371 * acos( cos( radians(?) ) * cos( radians(latitude) ) * cos( radians(longitude) - radians(?) ) + sin( radians(?) ) * sin( radians(latitude) ) ) ) AS distance',
                    [$lat, $lng, $lat]
                )
                ->having('distance', '<=', $radius)
                ->orderBy('distance');
        } else {
            $query->latest();
        }

        $perPage = (int) ($validated['per_page'] ?? 20);
        $rooms   = $query->paginate($perPage);

        $authUser      = auth('sanctum')->user();
        $wishlistedIds = $authUser
            ? $authUser->wishlists()->pluck('product_id')->toArray()
            : null;

        $data = collect($rooms->items())->map(function ($room) use ($wishlistedIds) {
            $status = $wishlistedIds === null ? null : in_array($room->id, $wishlistedIds);

            return $this->mapRoom($room, $status);
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $rooms->currentPage(),
                'last_page'    => $rooms->lastPage(),
                'per_page'     => $rooms->perPage(),
                'total'        => $rooms->total(),
            ],
        ]);
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\BuildsRoomCard;
use App\Http\Concerns\ResolvesProvince;
use App\Models\Province;
use App\Models\ProvinceBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomType;

class SearchController extends Controller
{
    use BuildsRoomCard, ResolvesProvince;

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
            'ward_code' => ['nullable', 'integer'],
            'page'      => ['nullable', 'integer', 'min:1'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Product::where('is_activated', true)
            ->where('is_in_stock', true)
            ->with(['roomTimeSlots.timeSlot', 'media', 'roomType']);

        // Auto-filter theo khu vực đã lưu (customer hoặc guest ?province_id=)
        $province = $this->resolveProvince($request);
        if ($province !== null) {
            $provinceBranchIds = $province->branches()
                ->where('status', true)
                ->pluck('categorie_id')
                ->toArray();

            if (! empty($provinceBranchIds)) {
                $childIds  = Category::whereIn('parent_id', $provinceBranchIds)->pluck('id');
                $filterIds = collect($provinceBranchIds)->merge($childIds)->unique()->values();
                $query->whereHas('categories', fn ($cq) => $cq->whereIn('category_id', $filterIds));
            }
        }

        // Filter theo ward — chi tiết hơn tỉnh, ghi đè filter tỉnh nếu có
        if (! empty($validated['ward_code'])) {
            $wardBranchIds = ProvinceBranch::where('ward_code', $validated['ward_code'])
                ->where('status', true)
                ->pluck('categorie_id')
                ->toArray();

            if (! empty($wardBranchIds)) {
                $childIds  = Category::whereIn('parent_id', $wardBranchIds)->pluck('id');
                $filterIds = collect($wardBranchIds)->merge($childIds)->unique()->values();
                $query->whereHas('categories', fn ($cq) => $cq->whereIn('category_id', $filterIds));
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // Filter theo category slug
        if (! empty($validated['category'])) {
            match ($validated['category']) {
                'theo_gio'  => $query->where('styles', 1),
                'theo_ngay' => $query->where('styles', 2),
                'qua_dem'   => $query->where('nights', true),
                default     => (function () use ($query, $validated) {
                    $roomType = RoomType::where('slug', $validated['category'])->first();
                    $roomType
                        ? $query->where('room_type_id', $roomType->id)
                        : $query->whereRaw('1 = 0');
                })(),
            };
        }

        // Keyword search
        if (! empty($validated['q'])) {
            $q = $validated['q'];

            // Khi province đã được auto-resolve thì không cần tìm theo tên tỉnh nữa
            $provinceCategoryIds = $province === null
                ? $this->getCategoryIdsByProvinceName($q)
                : [];

            $query->where(function ($sub) use ($q, $provinceCategoryIds) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('address', 'like', "%{$q}%")
                    ->orWhereHas('categories', fn ($cq) => $cq->where('name', 'like', "%{$q}%"));

                if (! empty($provinceCategoryIds)) {
                    $sub->orWhereHas('categories', fn ($cq) => $cq->whereIn('category_id', $provinceCategoryIds));
                }
            });
        }

        // Filter rooms by available time slots khi tìm theo_gio có time_from & time_to
        $timeFrom = null;
        $timeTo   = null;
        if (($validated['time_type'] ?? null) === 'slot'
            && ! empty($validated['time_from'])
            && ! empty($validated['time_to'])
        ) {
            $timeFrom = $validated['time_from'];
            $timeTo   = $validated['time_to'];

            $query->whereHas('roomTimeSlots', function ($sub) use ($timeFrom, $timeTo) {
                $sub->whereNull('date')
                    ->whereNotIn('status', ['booked'])
                    ->where('over_night', false)
                    ->whereHas('timeSlot', function ($slotSub) use ($timeFrom, $timeTo) {
                        $slotSub->where('start_time', '>=', $timeFrom)
                                ->where('end_time', '<=', $timeTo);
                    });
            });
        }

        // Geo search (Haversine — requires lat & lng together)
        $geoActive = isset($validated['lat'], $validated['lng']);

        if ($geoActive) {
            $lat    = (float) $validated['lat'];
            $lng    = (float) $validated['lng'];
            $radius = (float) ($validated['radius'] ?? 3);

            // LEAST(1.0, ...) tránh lỗi acos khi toạ độ trùng khớp chính xác
            $haversine = '( 6371 * acos( LEAST(1.0, '
                . 'cos( radians(?) ) * cos( radians(latitude) ) '
                . '* cos( radians(longitude) - radians(?) ) '
                . '+ sin( radians(?) ) * sin( radians(latitude) ) '
                . ') ) )';

            $query->whereNotNull('latitude')
                ->whereNotNull('longitude')
                // whereRaw hoạt động đúng trong cả data query lẫn COUNT query của paginate()
                ->whereRaw("{$haversine} <= ?", [$lat, $lng, $lat, $radius])
                ->selectRaw("*, {$haversine} AS distance", [$lat, $lng, $lat])
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

        $data = collect($rooms->items())->map(function ($room) use ($wishlistedIds, $geoActive, $timeFrom, $timeTo) {
            $status = $wishlistedIds === null ? null : in_array($room->id, $wishlistedIds);
            $card   = $this->mapRoom($room, $status, $timeFrom, $timeTo);

            if ($geoActive) {
                $card['distance'] = round((float) ($room->distance ?? 0), 2);
            }

            return $card;
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

    // ─── Helpers ─────────────────────────────────────────────────────────────

    // Tìm tỉnh khớp tên → trả về category IDs (branch + child) để filter phòng
    private function getCategoryIdsByProvinceName(string $q): array
    {
        $provinceIds = Province::where('name', 'like', "%{$q}%")->pluck('id');

        if ($provinceIds->isEmpty()) {
            return [];
        }

        $branchCategoryIds = ProvinceBranch::whereIn('province_id', $provinceIds)
            ->where('status', true)
            ->pluck('categorie_id')
            ->toArray();

        if (empty($branchCategoryIds)) {
            return [];
        }

        $childIds = Category::whereIn('parent_id', $branchCategoryIds)
            ->pluck('id')
            ->toArray();

        return array_unique(array_merge($branchCategoryIds, $childIds));
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

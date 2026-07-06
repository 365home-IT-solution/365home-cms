<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\BuildsRoomCard;
use App\Http\Concerns\ResolvesProvince;
use App\Models\Province;
use App\Models\ProvinceBranch;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\OrderItem;
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

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category'  => ['nullable', 'string'],
            'type'      => ['nullable', 'string'],
            'buoi'      => ['nullable', Rule::in(['1', '2'])],
            'checkin'   => ['nullable', 'string'],
            'checkout'  => ['nullable', 'string'],
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
            ->with(['roomTimeSlots.timeSlot', 'media', 'roomType', 'categories:id']);

        // branchCatIds/branchChildMap dùng để gắn thông tin "chi nhánh" (branch) vào từng phòng
        // trả về, cho phép client tự nhóm phòng theo chi nhánh — chi tiết hơn (ward) ghi đè
        // tỉnh nếu có, giống thứ tự ưu tiên filter bên dưới.
        $branchCatIds  = [];
        $branchChildMap = [];

        // Auto-filter theo khu vực đã lưu (customer hoặc guest ?province_id=)
        $province = $this->resolveProvince($request);

        // "location" (path /s/{location} → query ?province=) có thể là slug tỉnh (ở trên) hoặc —
        // khi nút "Xem tất cả" của 1 khối phòng theo chi nhánh cụ thể được bấm — slug của 1 hoặc
        // nhiều chi nhánh (category cha, cách nhau bởi dấu phẩy), vd /s/254-xuan-thuy-an-binh-can-tho
        // hoặc /s/branch-a,branch-b khi khối đó chọn nhiều chi nhánh cùng lúc.
        $branchCategories = null;
        if ($province === null && $request->filled('province')) {
            $slugs = array_values(array_filter(array_map('trim', explode(',', (string) $request->query('province')))));
            if (! empty($slugs)) {
                $branchCategories = Category::whereIn('slug', $slugs)
                    ->whereNull('parent_id')
                    ->where('category_type', 'product')
                    ->get();
            }
        }

        if ($province !== null) {
            $provinceBranchIds = $province->branches()
                ->where('status', true)
                ->pluck('categorie_id')
                ->toArray();

            if (! empty($provinceBranchIds)) {
                $childCats = Category::whereIn('parent_id', $provinceBranchIds)->get(['id', 'parent_id']);
                $filterIds = collect($provinceBranchIds)->merge($childCats->pluck('id'))->unique()->values();
                $query->whereHas('categories', fn ($cq) => $cq->whereIn('category_id', $filterIds));

                $branchCatIds   = $provinceBranchIds;
                $branchChildMap = $childCats->pluck('parent_id', 'id')->toArray();
            } else {
                // Province tồn tại nhưng không có branch active → trả về rỗng
                $query->whereRaw('1 = 0');
            }
        } elseif ($branchCategories !== null) {
            if ($branchCategories->isNotEmpty()) {
                $branchIds = $branchCategories->pluck('id')->toArray();
                $childCats = Category::whereIn('parent_id', $branchIds)->get(['id', 'parent_id']);
                $filterIds = collect($branchIds)->merge($childCats->pluck('id'))->unique()->values();
                $query->whereHas('categories', fn ($cq) => $cq->whereIn('category_id', $filterIds));

                $branchCatIds   = $branchIds;
                $branchChildMap = $childCats->pluck('parent_id', 'id')->toArray();
            } else {
                // location không khớp cả tỉnh lẫn chi nhánh nào → trả về rỗng thay vì hiện lố toàn quốc
                $query->whereRaw('1 = 0');
            }
        }

        // Filter theo ward — chi tiết hơn tỉnh, ghi đè filter tỉnh nếu có
        if (! empty($validated['ward_code'])) {
            $wardBranchIds = ProvinceBranch::where('ward_code', $validated['ward_code'])
                ->where('status', true)
                ->pluck('categorie_id')
                ->toArray();

            if (! empty($wardBranchIds)) {
                $childCats = Category::whereIn('parent_id', $wardBranchIds)->get(['id', 'parent_id']);
                $filterIds = collect($wardBranchIds)->merge($childCats->pluck('id'))->unique()->values();
                $query->whereHas('categories', fn ($cq) => $cq->whereIn('category_id', $filterIds));

                $branchCatIds   = $wardBranchIds;
                $branchChildMap = $childCats->pluck('parent_id', 'id')->toArray();
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // Filter theo category slug (giữ tương thích ngược — theo_gio/theo_ngay/qua_dem hoặc room
        // type slug, không kết hợp được với nhau trong 1 request)
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

        // Filter theo room type slug + kiểu tính giờ/ngày — 2 tham số riêng, dùng độc lập và có
        // thể kết hợp cùng lúc (vd type=homestay & buoi=1), khác với "category" ở trên.
        $typeName = null;
        if (! empty($validated['type']) && $validated['type'] !== 'all') {
            $roomType = RoomType::where('slug', $validated['type'])->first();
            if ($roomType) {
                $query->where('room_type_id', $roomType->id);
                $typeName = $roomType->name;
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if (in_array($validated['buoi'] ?? null, ['1', '2'], true)) {
            $query->where('styles', (int) $validated['buoi']);
        }

        // Loại phòng đã bị đặt trong khung giờ tìm kiếm (checkin/checkout dạng d/m/Y H:i, đến từ
        // hero search — khác định dạng Y-m-d của time_type=day/date ở trên).
        $bookedIds = $this->resolveBookedIds($validated['checkin'] ?? null, $validated['checkout'] ?? null);
        if (! empty($bookedIds)) {
            $query->whereNotIn('id', $bookedIds);
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

        // Không lọc theo tỉnh/ward cụ thể (vd bấm room type từ trang chủ, tìm toàn quốc) — vẫn
        // cần xác định chi nhánh của từng phòng để client nhóm hiển thị (mỗi chi nhánh 1 dòng),
        // nên lấy toàn bộ chi nhánh đang active trên hệ thống làm tập tra cứu.
        if (empty($branchCatIds)) {
            $branchCatIds = ProvinceBranch::where('status', true)->pluck('categorie_id')->unique()->values()->toArray();
            if (! empty($branchCatIds)) {
                $childCats      = Category::whereIn('parent_id', $branchCatIds)->get(['id', 'parent_id']);
                $branchChildMap = $childCats->pluck('parent_id', 'id')->toArray();
            }
        }

        $branchCats = ! empty($branchCatIds)
            ? Category::whereIn('id', $branchCatIds)->get(['id', 'name', 'slug'])->keyBy('id')
            : collect();

        $data = collect($rooms->items())->map(function ($room) use ($wishlistedIds, $geoActive, $timeFrom, $timeTo, $branchCats, $branchChildMap) {
            $status = $wishlistedIds === null ? null : in_array($room->id, $wishlistedIds);
            $card   = $this->mapRoom($room, $status, $timeFrom, $timeTo);

            $card['address'] = $room->address;

            // Chi nhánh mà phòng này thuộc về (trực tiếp hoặc qua category con) — để client tự
            // nhóm phòng theo chi nhánh khi hiển thị (mỗi chi nhánh 1 dòng riêng).
            $card['branch'] = null;
            if ($branchCats->isNotEmpty()) {
                foreach ($room->categories as $cat) {
                    $branchCatId = null;
                    if ($branchCats->has($cat->id)) {
                        $branchCatId = $cat->id;
                    } elseif (isset($branchChildMap[$cat->id])) {
                        $branchCatId = $branchChildMap[$cat->id];
                    }
                    if ($branchCatId && $branchCats->has($branchCatId)) {
                        $branch = $branchCats->get($branchCatId);
                        $card['branch'] = [
                            'id'   => $branch->id,
                            'name' => $branch->name,
                            'slug' => $branch->slug,
                        ];
                        break;
                    }
                }
            }

            if ($geoActive) {
                $card['distance'] = round((float) ($room->distance ?? 0), 2);
            }

            return $card;
        });

        $locationName = $province?->name
            ?? ($branchCategories?->isNotEmpty() ? $branchCategories->pluck('name')->implode(', ') : null);

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page'  => $rooms->currentPage(),
                'last_page'     => $rooms->lastPage(),
                'per_page'      => $rooms->perPage(),
                'total'         => $rooms->total(),
                'province_name' => $locationName,
                'type_name'     => $typeName,
            ],
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    // Lấy danh sách product_id đã bị đặt trong khung giờ tìm kiếm (checkin/checkout dạng
    // d/m/Y H:i, đến từ hero search) — overlap: checkin_date < checkout AND checkout_date > checkin
    private function resolveBookedIds(?string $checkIn, ?string $checkOut): array
    {
        if (! $checkIn || ! $checkOut) {
            return [];
        }

        try {
            $in  = Carbon::createFromFormat('d/m/Y H:i', $checkIn);
            $out = Carbon::createFromFormat('d/m/Y H:i', $checkOut);
        } catch (\Exception) {
            return [];
        }

        if ($out->lte($in)) {
            return [];
        }

        return OrderItem::whereHas('order', fn ($q) => $q->whereIn('status', ['paid', 'deposit', 'shipped', 'confirmed']))
            ->whereNotNull('product_id')
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->where('checkin_date', '<', $out)
            ->where('checkout_date', '>', $in)
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }

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

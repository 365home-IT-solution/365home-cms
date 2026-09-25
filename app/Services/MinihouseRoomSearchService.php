<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Province;
use App\Models\ProvinceBranch;
use App\Support\ImagePresetUrls;
use App\Support\MediaThumbnailUrls;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\BuildingSetting;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Room;
use Modules\Product\App\Models\RoomType;

// Tìm/liệt kê PHÒNG MINIHOUSE CÒN TRỐNG (thuê dài hạn, chưa cho thuê) cho app/web khách — dùng
// chung cho: tab MiniHouse ở GET /api/v1/home (HomeController), GET /api/v1/minihouse/rooms[/nearby]
// (MinihouseRoomController) và GET /api/v1/search khi đang ở tab MiniHouse (SearchController).
//
// "Còn trống" = trạng thái phòng "Trống" (Room::scopeAvailable()) + KHÔNG có hợp đồng đang hiệu lực
// (phòng quên đổi trạng thái sau khi ký HĐ vẫn không lọt ra) + phòng đang bật + toà nhà đang bật.
//
// "Gần tôi" tính theo toạ độ của PHÒNG (products.latitude/longitude — nhập ở form Phòng MiniHouse,
// toà nhà không có toạ độ). Phòng CHƯA nhập toạ độ vẫn được trả về nếu toà nhà của nó cùng tỉnh
// với vị trí khách (tỉnh gần nhất theo lat/lng, hoặc province_id/province gửi lên) — xếp SAU các
// phòng có khoảng cách, distance = null — để khách không nhận danh sách rỗng chỉ vì admin chưa
// nhập toạ độ.
class MinihouseRoomSearchService
{
    // Tab trên app: "mini_house" (id 3, loại hình hiển thị trên thanh tab) và "minihouse" (loại
    // hình thật của phòng dài hạn, đang tắt nên không hiện tab) — cả 2 đều chuyển sang dữ liệu MiniHouse.
    public const TAB_SLUGS = ['mini_house', RoomType::MINIHOUSE_SLUG];

    public const DEFAULT_NEARBY_RADIUS_KM = 10;

    public static function isMinihouseRoomType(int|string|null $roomType): bool
    {
        if ($roomType === null || $roomType === '') {
            return false;
        }

        $slug = is_numeric($roomType)
            ? RoomType::whereKey((int) $roomType)->value('slug')
            : (string) $roomType;

        return in_array(str_replace('-', '_', (string) $slug), self::TAB_SLUGS, true);
    }

    private static function validationRules(): array
    {
        return [
            'q'             => ['nullable', 'string', 'max:255'],
            'building_id'   => ['nullable', 'integer'],
            'province_id'   => ['nullable', 'integer'],
            'province'      => ['nullable', 'string'],
            'lat'           => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng'           => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius'        => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'min_price'     => ['nullable', 'numeric', 'min:0'],
            'max_price'     => ['nullable', 'numeric', 'min:0'],
            'min_area'      => ['nullable', 'numeric', 'min:0'],
            'max_area'      => ['nullable', 'numeric', 'min:0'],
            'amenity_ids'   => ['nullable', 'array'],
            'amenity_ids.*' => ['integer'],
            'sort'          => ['nullable', 'in:distance,latest,price_asc,price_desc,area_asc,area_desc'],
            'page'          => ['nullable', 'integer', 'min:1'],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Danh sách phân trang — shape {data, meta} giống RoomSearchService::search() để app dùng lại
     * màn kết quả tìm kiếm.
     */
    public function search(array $filters, ?Authenticatable $authUser = null): array
    {
        $validated = Validator::make($filters, self::validationRules())->validate();

        $query    = $this->vacantQuery();
        $province = $this->resolveProvince($validated);
        $geo      = isset($validated['lat'], $validated['lng']);

        $this->applyFilters($query, $validated);

        if ($geo) {
            $this->applyGeo(
                $query,
                (float) $validated['lat'],
                (float) $validated['lng'],
                (float) ($validated['radius'] ?? self::DEFAULT_NEARBY_RADIUS_KM),
                $province,
            );
        } elseif ($province !== null) {
            $query->whereIn('products.building_id', $this->buildingIdsInProvince($province));
        }

        $this->applySort($query, $validated['sort'] ?? ($geo ? 'distance' : 'latest'), $geo);

        $rooms = $query->paginate((int) ($validated['per_page'] ?? 12));

        return [
            'data'      => $this->toCards(collect($rooms->items()), $this->wishlistedIds($authUser), $geo),
            // Toà nhà khớp từ khoá (tên/địa chỉ/tỉnh/phường) và còn phòng trống — để khách gõ tên toà
            // nhà thấy ngay toà đó, bấm vào thì gọi lại API này với ?building_id= để xem phòng.
            'buildings' => $this->matchingBuildings(trim((string) ($validated['q'] ?? ''))),
            'meta'      => [
                'current_page'  => $rooms->currentPage(),
                'last_page'     => $rooms->lastPage(),
                'per_page'      => $rooms->perPage(),
                'total'         => $rooms->total(),
                'province_name' => $province?->name,
                'type_name'     => 'MiniHouse',
            ],
        ];
    }

    /**
     * Phòng trống gần vị trí khách (không phân trang) — dùng cho section "MiniHouse gần bạn" ở home.
     * Không có lat/lng thì rơi về phòng trống cùng tỉnh ($province), không có cả 2 → rỗng.
     */
    public function nearby(?float $lat, ?float $lng, ?Province $province, float $radius = self::DEFAULT_NEARBY_RADIUS_KM, int $limit = 10, ?Authenticatable $authUser = null): array
    {
        $geo = $lat !== null && $lng !== null;

        if (! $geo && $province === null) {
            return [];
        }

        $query = $this->vacantQuery();

        if ($geo) {
            $this->applyGeo($query, $lat, $lng, $radius, $province ?? $this->nearestProvince($lat, $lng));
            $this->applySort($query, 'distance', true);
        } else {
            $query->whereIn('products.building_id', $this->buildingIdsInProvince($province));
            $this->applySort($query, 'latest', false);
        }

        return $this->toCards($query->limit($limit)->get(), $this->wishlistedIds($authUser), $geo);
    }

    /** Phòng trống mới nhất (toàn hệ thống) — section "Phòng trống" ở home. */
    public function latest(int $limit = 10, ?Authenticatable $authUser = null): array
    {
        $query = $this->vacantQuery();
        $this->applySort($query, 'latest', false);

        return $this->toCards($query->limit($limit)->get(), $this->wishlistedIds($authUser), false);
    }

    public function vacantQuery(): Builder
    {
        return $this->whereVacant(Room::query()->select('products.*'))
            ->whereHas('building', fn ($q) => $q->where('status', true))
            ->with(['building', 'detail', 'roomType:id,slug,name', 'media']);
    }

    // Điều kiện "còn trống, chưa cho thuê" của 1 phòng — dùng cả cho truy vấn phòng lẫn whereHas
    // từ toà nhà (matchingBuildings()) để 2 nơi không lệch nhau.
    private function whereVacant(Builder $query): Builder
    {
        return $query
            ->available()
            ->where('products.is_activated', true)
            ->whereDoesntHave('contracts', fn ($q) => $q->where('status', Contract::STATUS_ACTIVE));
    }

    private function matchingBuildings(string $keyword): array
    {
        if ($keyword === '') {
            return [];
        }

        $like = '%' . $keyword . '%';
        $idsByAddress = BuildingSetting::where('address', 'like', $like)
            ->orWhere('province_name_raw', 'like', $like)
            ->orWhere('ward_raw', 'like', $like)
            ->pluck('category_id');

        return Building::query()
            ->where('status', true)
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhereIn('id', $idsByAddress))
            ->whereHas('rooms', fn ($q) => $this->whereVacant($q))
            ->withCount(['rooms as vacant_room_count' => fn ($q) => $this->whereVacant($q)])
            ->withMin(['rooms as min_price' => fn ($q) => $this->whereVacant($q)], 'price')
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (Building $building) => [
                'id'                => $building->id,
                'name'              => $building->name,
                'slug'              => $building->slug,
                'address'           => $building->address,
                'province'          => $building->province,
                'ward'              => $building->ward,
                'image_url'         => $building->image ? Storage::disk('public')->url($building->image) : null,
                'thumbnail'         => ImagePresetUrls::build($building->image, 'public'),
                'vacant_room_count' => (int) $building->vacant_room_count,
                'min_price'         => $building->min_price !== null ? (float) $building->min_price : null,
                'unit_label'        => '/ tháng',
            ])
            ->values()
            ->all();
    }

    private function applyFilters(Builder $query, array $validated): void
    {
        if (! empty($validated['building_id'])) {
            $query->where('products.building_id', (int) $validated['building_id']);
        }

        foreach (['min_price' => '>=', 'max_price' => '<='] as $key => $op) {
            if (isset($validated[$key])) {
                $query->where('products.price', $op, (float) $validated[$key]);
            }
        }

        foreach (['min_area' => '>=', 'max_area' => '<='] as $key => $op) {
            if (isset($validated[$key])) {
                $query->where('products.room_area_sqm', $op, (float) $validated[$key]);
            }
        }

        foreach ((array) ($validated['amenity_ids'] ?? []) as $amenityId) {
            $query->whereHas('amenities', fn ($q) => $q->where('minihouse_amenities.id', (int) $amenityId));
        }

        $keyword = trim((string) ($validated['q'] ?? ''));
        if ($keyword !== '') {
            // Địa chỉ/tỉnh/phường của toà nhà là thuộc tính ẢO (minihouse_building_settings), không
            // phải cột trên categories — tra ở BuildingSetting rồi lọc theo building_id.
            $like = '%' . $keyword . '%';
            $buildingIdsByAddress = BuildingSetting::where('address', 'like', $like)
                ->orWhere('province_name_raw', 'like', $like)
                ->orWhere('ward_raw', 'like', $like)
                ->pluck('category_id');

            $query->where(fn ($q) => $q
                ->where('products.name', 'like', $like)
                ->orWhere('products.address', 'like', $like)
                ->orWhereHas('building', fn ($b) => $b->where('name', 'like', $like))
                ->orWhereIn('products.building_id', $buildingIdsByAddress));
        }
    }

    // Trong bán kính (phòng có toạ độ) HOẶC chưa có toạ độ nhưng toà nhà cùng tỉnh $fallbackProvince.
    private function applyGeo(Builder $query, float $lat, float $lng, float $radius, ?Province $fallbackProvince): void
    {
        // Raw SQL không tự thêm prefix bảng (cms_) như query builder — wrap() để ra đúng tên cột.
        $grammar = $query->getQuery()->getGrammar();
        $latCol  = $grammar->wrap('products.latitude');
        $lngCol  = $grammar->wrap('products.longitude');

        $hasCoords = "{$latCol} IS NOT NULL AND {$lngCol} IS NOT NULL "
            . "AND NOT ({$latCol} = 0 AND {$lngCol} = 0)";

        // LEAST(1.0, ...) tránh lỗi acos khi toạ độ trùng khớp chính xác (như RoomSearchService).
        $haversine = '( 6371 * acos( LEAST(1.0, '
            . "cos( radians(?) ) * cos( radians({$latCol}) ) "
            . "* cos( radians({$lngCol}) - radians(?) ) "
            . "+ sin( radians(?) ) * sin( radians({$latCol}) ) "
            . ') ) )';

        $fallbackBuildingIds = $fallbackProvince ? $this->buildingIdsInProvince($fallbackProvince) : [];

        $query->where(function ($q) use ($hasCoords, $haversine, $lat, $lng, $radius, $fallbackBuildingIds) {
            $q->whereRaw("({$hasCoords}) AND {$haversine} <= ?", [$lat, $lng, $lat, $radius]);

            if ($fallbackBuildingIds !== []) {
                $q->orWhere(fn ($q2) => $q2
                    ->whereRaw("NOT ({$hasCoords})")
                    ->whereIn('products.building_id', $fallbackBuildingIds));
            }
        })->selectRaw("CASE WHEN {$hasCoords} THEN {$haversine} ELSE NULL END AS distance", [$lat, $lng, $lat]);
    }

    private function applySort(Builder $query, string $sort, bool $geo): void
    {
        match (true) {
            $sort === 'distance' && $geo => $query->orderByRaw('distance IS NULL')->orderBy('distance'),
            $sort === 'price_asc'        => $query->orderBy('products.price'),
            $sort === 'price_desc'       => $query->orderByDesc('products.price'),
            $sort === 'area_asc'         => $query->orderBy('products.room_area_sqm'),
            $sort === 'area_desc'        => $query->orderByDesc('products.room_area_sqm'),
            default                      => $query->orderByDesc('products.created_at'),
        };

        $query->orderByDesc('products.id');
    }

    // Toà nhà thuộc tỉnh: gắn qua province_branches (như chi nhánh Home) HOẶC tên tỉnh nhập tay ở
    // cài đặt toà nhà (province_name_raw, vd "Hồ Chí Minh" so với Province "Thành phố Hồ Chí Minh").
    private function buildingIdsInProvince(Province $province): array
    {
        $shortName = trim((string) preg_replace('/^(Thành phố|Tỉnh|TP\.?)\s+/iu', '', $province->name));

        return ProvinceBranch::where('province_id', $province->id)->pluck('categorie_id')
            ->merge(
                BuildingSetting::where('province_name_raw', 'like', '%' . $shortName . '%')->pluck('category_id')
            )
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function resolveProvince(array $validated): ?Province
    {
        if (! empty($validated['province_id'])) {
            return Province::find((int) $validated['province_id']);
        }

        if (! empty($validated['province'])) {
            return Province::where('slug', $validated['province'])->first();
        }

        if (isset($validated['lat'], $validated['lng'])) {
            return $this->nearestProvince((float) $validated['lat'], (float) $validated['lng']);
        }

        return null;
    }

    public function nearestProvince(float $lat, float $lng): ?Province
    {
        return Province::whereNotNull('lat')
            ->whereNotNull('lng')
            ->get()
            ->sortBy(fn (Province $p) => ($p->lat - $lat) ** 2 + (($p->lng - $lng) * cos(deg2rad($lat))) ** 2)
            ->first();
    }

    private function wishlistedIds(?Authenticatable $authUser): ?array
    {
        return $authUser && method_exists($authUser, 'wishlists')
            ? $authUser->wishlists()->pluck('product_id')->all()
            : null;
    }

    // Card phòng MiniHouse — cùng khung với BuildsRoomCard::mapMinihouseRoom() (id/slug/name/
    // thumbnail/price/unit_label '/ tháng'...) để app dùng chung component card phòng, thêm thông tin
    // toà nhà/diện tích/tầng và khoảng cách.
    private function toCards(Collection $rooms, ?array $wishlistedIds, bool $geo): array
    {
        return $rooms->map(function (Room $room) use ($wishlistedIds, $geo) {
            [$coverUrl, $thumbnail] = $this->cover($room);
            $building = $room->building;

            $card = [
                'id'              => $room->id,
                'slug'            => $room->slug,
                'name'            => $room->name,
                'type_slug'       => RoomType::MINIHOUSE_SLUG,
                'thumbnail_url'   => $coverUrl,
                'thumbnail'       => $thumbnail,
                'room_style'      => 'theo_thang',
                'badge'           => null,
                'price'           => [
                    'amount'     => (float) $room->price,
                    'unit_label' => '/ tháng',
                ],
                'area'            => $room->area,
                'floor'           => $room->floor,
                'rating'          => $room->rating_score !== null ? (float) $room->rating_score : null,
                'wishlist_status' => $wishlistedIds === null ? null : in_array($room->id, $wishlistedIds),
                'is_available'    => true,
                'address'         => $room->address ?: $building?->address,
                'latitude'        => $room->latitude ? (string) $room->latitude : null,
                'longitude'       => $room->longitude ? (string) $room->longitude : null,
                'building'        => $building ? [
                    'id'       => $building->id,
                    'name'     => $building->name,
                    'slug'     => $building->slug,
                    'address'  => $building->address,
                    'province' => $building->province,
                    'ward'     => $building->ward,
                ] : null,
            ];

            if ($geo) {
                $card['distance'] = $room->distance !== null ? round((float) $room->distance, 2) : null;
            }

            return $card;
        })->values()->all();
    }

    // Ảnh bìa: Media Library ("Ảnh bìa" → "Thư viện", nguồn chính của form Phòng) rồi mới tới đường
    // dẫn cũ ở minihouse_room_details.photos (xem Room::getPhotosAttribute()).
    private function cover(Room $room): array
    {
        $media = $room->getFirstMedia('Ảnh bìa') ?? $room->getFirstMedia('Thư viện');
        if ($media) {
            return [$media->getUrl(), MediaThumbnailUrls::build($media)];
        }

        $path = collect((array) ($room->detail?->photos ?? []))->filter()->first();
        if (! $path) {
            return [null, null];
        }

        if (str_starts_with($path, 'http')) {
            return [$path, null];
        }

        return [Storage::disk('public')->url($path), ImagePresetUrls::build($path, 'public')];
    }
}

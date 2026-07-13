<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Concerns\BuildsRoomCard;
use App\Models\Customer;
use App\Models\Province;
use App\Models\ProvinceBranch;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomType;

/**
 * Logic tìm kiếm phòng — dùng chung cho REST (SearchController::index) và GraphQL
 * (GraphQLController), để tránh chép lại cùng 1 bộ filter (tỉnh/ward/chi nhánh, khoảng
 * cách Haversine, time slot, từ khoá...) ở 2 nơi.
 */
class RoomSearchService
{
    use BuildsRoomCard;

    public function search(array $filters, ?Customer $authUser = null): array
    {
        $validated = Validator::make($filters, [
            'category'  => ['nullable', 'string'],
            'type'      => ['nullable', 'string'],
            'buoi'      => ['nullable', 'in:1,2'],
            'overnight' => ['nullable', 'in:0,1'],
            'checkin'   => ['nullable', 'string'],
            'checkout'  => ['nullable', 'string'],
            'q'         => ['nullable', 'string', 'max:255'],
            'lat'       => ['nullable', 'numeric', 'between:-90,90'],
            'lng'       => ['nullable', 'numeric', 'between:-180,180'],
            'radius'    => ['nullable', 'numeric', 'min:0'],
            'time_type' => ['nullable', 'in:slot,day,month'],
            'date'      => ['nullable', 'date_format:Y-m-d', 'required_if:time_type,slot'],
            'time_from' => ['nullable', 'date_format:H:i'],
            'time_to'   => ['nullable', 'date_format:H:i'],
            'from'      => ['nullable', 'date_format:Y-m-d', 'required_if:time_type,day'],
            'to'        => ['nullable', 'date_format:Y-m-d', 'required_if:time_type,day', 'after_or_equal:from'],
            'month'     => ['nullable', 'integer', 'between:1,12', 'required_if:time_type,month'],
            'year'      => ['nullable', 'integer', 'min:2020', 'required_if:time_type,month'],
            'adults'    => ['nullable', 'integer', 'min:1'],
            'ward_code' => ['nullable', 'integer'],
            'province'  => ['nullable', 'string'],
            'province_id' => ['nullable', 'integer'],
            'page'      => ['nullable', 'integer', 'min:1'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ])->validate();

        $query = Product::where('is_activated', true)
            ->where('is_in_stock', true)
            ->with(['roomTimeSlots.timeSlot', 'media', 'roomType', 'categories:id']);

        // branchCatIds/branchChildMap dùng để gắn thông tin "chi nhánh" (branch) vào từng phòng
        // trả về, cho phép client tự nhóm phòng theo chi nhánh — chi tiết hơn (ward) ghi đè
        // tỉnh nếu có, giống thứ tự ưu tiên filter bên dưới.
        $branchCatIds  = [];
        $branchChildMap = [];

        $province = $this->resolveProvince($filters, $authUser);

        // "location" (path /s/{location} → query ?province=) có thể là slug tỉnh (ở trên) hoặc —
        // khi nút "Xem tất cả" của 1 khối phòng theo chi nhánh cụ thể được bấm — slug của 1 hoặc
        // nhiều chi nhánh (category cha, cách nhau bởi dấu phẩy), vd /s/254-xuan-thuy-an-binh-can-tho
        // hoặc /s/branch-a,branch-b khi khối đó chọn nhiều chi nhánh cùng lúc.
        $branchCategories = null;
        if ($province === null && ! empty($filters['province'])) {
            $slugs = array_values(array_filter(array_map('trim', explode(',', (string) $filters['province']))));
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

        // Tab "Qua đêm" (buoi=1 + overnight=1): phòng đạt tiêu chí này nếu khớp 1 TRONG 2 điều
        // kiện (OR, không phải AND) — tự nó đã được cấu hình cho ở qua đêm (cột products.nights),
        // HOẶC có ít nhất 1 khung giờ (room_time_slots) được đánh dấu qua đêm (over_night=true,
        // xem book/_desktop-grid.blade.php dùng cùng khái niệm này để hiện icon "(Qua đêm)"). Chỉ
        // cần 1 trong 2 là đủ hiện ra kết quả — không bắt buộc có cả 2.
        if (($validated['overnight'] ?? null) === '1') {
            $query->where(function ($sub) {
                $sub->where('nights', true)
                    ->orWhereHas('roomTimeSlots', fn ($tsq) => $tsq->where('over_night', true));
            });
        }

        // Lọc theo số khách — loại phòng nếu adults > "Số khách tối đa không phụ thu"
        // (room_config.max_free_guests, mặc định 2 nếu phòng chưa cấu hình — khớp đúng giá trị
        // mặc định dùng khi tính phụ thu ở nơi khác, vd ProductDetail.php/Book.php). Không có
        // trường sức chứa cứng nào khác trên Product, nên coi ngưỡng miễn phụ thu này là ngưỡng
        // hiển thị trong tìm kiếm — phòng cần phụ thu cho số khách đó thì không hiện ra nữa.
        if (! empty($validated['adults'])) {
            $query->whereRaw(
                "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(room_config, '$.max_free_guests')) AS UNSIGNED), 2) >= ?",
                [(int) $validated['adults']]
            );
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

        // Filter rooms by available time slots khi tìm theo_gio có time_from & time_to — khớp
        // theo kiểu OVERLAP (khung giờ của phòng chỉ cần giao nhau với [time_from, time_to] khách
        // chọn). BẮT BUỘC dùng overlap chứ không phải containment: dữ liệu time_slots thực tế mỗi
        // phòng dài ~2h50-3h và lệch phút (vd 13:50-16:40, 14:20-17:10...), gần như không có phòng
        // nào nằm gọn trong khung giờ tròn khách chọn trên UI (mặc định 14:00-16:00) — containment
        // trả về 0 kết quả ngay cả khi phòng thực sự trống trong khoảng đó. Client hiển thị đúng
        // khung giờ thực của từng phòng (không phải khung giờ khách tìm) nên overlap không gây
        // hiểu nhầm.
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
                        $slotSub->where('start_time', '<', $timeTo)
                                ->where('end_time', '>', $timeFrom);
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

        return [
            'data' => $data->values()->all(),
            'meta' => [
                'current_page'  => $rooms->currentPage(),
                'last_page'     => $rooms->lastPage(),
                'per_page'      => $rooms->perPage(),
                'total'         => $rooms->total(),
                'province_name' => $locationName,
                'type_name'     => $typeName,
            ],
        ];
    }

    // Giống ResolvesProvince::resolveProvince(), nhưng nhận filters dạng mảng (không phải
    // Illuminate\Http\Request) để dùng chung được cho cả REST lẫn GraphQL variables.
    private function resolveProvince(array $filters, ?Customer $authUser): ?Province
    {
        if (! empty($filters['province_id'])) {
            return Province::find((int) $filters['province_id']);
        }

        if (! empty($filters['province'])) {
            if (! str_contains((string) $filters['province'], ',')) {
                $bySlug = Province::where('slug', $filters['province'])->first();
                if ($bySlug) {
                    return $bySlug;
                }
            }

            // Có truyền province nhưng không khớp tỉnh nào (vd slug chi nhánh cụ thể như trang
            // /branch/{slug}, hoặc nhiều chi nhánh cách nhau bởi dấu phẩy) — đây là lựa chọn địa
            // điểm CỐ Ý, hẹp hơn cả tỉnh, nên không được rơi xuống dùng province_id mặc định của
            // tài khoản (sẽ vô tình mở rộng kết quả ra nguyên tỉnh, đè mất bộ lọc theo chi nhánh
            // cụ thể mà trang gọi API đã yêu cầu).
            return null;
        }

        if ($authUser?->province_id) {
            return Province::find($authUser->province_id);
        }

        return null;
    }

    // Lấy danh sách product_id đã bị đặt trong khung giờ tìm kiếm (checkin/checkout dạng
    // d/m/Y H:i, đến từ hero search — overlap: checkin_date < checkout AND checkout_date > checkin
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
}

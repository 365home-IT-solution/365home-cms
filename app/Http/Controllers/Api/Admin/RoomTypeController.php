<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\RoomType;

/**
 * Danh mục phòng (bảng `room_types`, vd "Theo giờ", "Theo ngày"...) — CRUD đầy đủ, dùng cho dropdown
 * lọc/gán `room_type_id` ở products (xem ProductController::updateRoomType() — gán danh mục này cho
 * 1 phòng). Đây là danh mục DÙNG CHUNG cho mọi đối tác (không có partner_id/global scope theo
 * partner như `products`), khác với App\Http\Controllers\Api\RoomTypeController (API phía khách
 * hàng, chỉ trả room_type is_active kèm danh sách chi nhánh/phòng — mục đích khác hẳn, không dùng
 * cho admin quản trị).
 */
class RoomTypeController extends Controller
{
    /**
     * GET /api/admin/room-types
     * Query params:
     *  - is_active  : lọc theo trạng thái hiển thị — 1/0/true/false. Mặc định (không truyền) trả
     *                 TẤT CẢ (kể cả đang tắt) vì admin cần thấy toàn bộ danh mục để quản lý, khác
     *                 API khách hàng luôn ép is_active=true.
     *  - categories : danh sách slug chi nhánh/khu vực, PHÂN TÁCH DẤU PHẨY (vd
     *                 ?categories=254-xuan-thuy-an-binh-can-tho,252-xuan-thuy-phuong-an-binh-can-tho)
     *                 — chỉ tính room_count/price trên các phòng THUỘC 1 TRONG các chi nhánh/khu vực
     *                 này (mỗi slug tự mở rộng ra khu vực con, giống category_id ở GET /products).
     *                 Slug không khớp chi nhánh nào -> mọi room_type trả room_count=0, price=null
     *                 (KHÔNG loại bỏ hẳn room_type khỏi danh sách — đây là danh mục dùng chung, rỗng
     *                 dữ liệu phòng ở phạm vi lọc không có nghĩa là danh mục "không tồn tại").
     *  - type       : 'gio' (phòng theo khung giờ, styles=1) hoặc 'ngay' (phòng theo ngày, styles=2)
     *                 — chỉ tính room_count/price trên phòng ĐÚNG kiểu này.
     *
     * Mỗi room_type trả kèm:
     *  - room_count : số phòng (products, đang active) thuộc danh mục này, đã áp dụng filter
     *                 categories/type ở trên nếu có.
     *  - price      : giá thấp nhất (MIN) trong số các phòng thoả room_count ở trên — phòng styles=1
     *                 lấy giá của khung giờ ĐẦU TIÊN trong ngày (start_time nhỏ nhất, bảng
     *                 room_time_slots); phòng styles=2 lấy thẳng cột products.price. null nếu
     *                 room_count=0 hoặc không phòng nào có giá.
     */
    public function index(Request $request): JsonResponse
    {
        $query = RoomType::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $categoryIds = null;
        if ($request->filled('categories')) {
            $slugs = array_values(array_filter(array_map('trim', explode(',', (string) $request->string('categories')))));

            $matchedIds  = Category::where('category_type', 'product')->whereIn('slug', $slugs)->pluck('id')->all();
            $categoryIds = collect($matchedIds)->flatMap(fn ($id) => $this->expandCategoryIds($id))->unique()->values()->all();
        }

        $styles = null;
        if ($request->filled('type')) {
            $type = (string) $request->string('type');

            if (! in_array($type, ['ngay', 'gio'], true)) {
                return response()->json(['message' => 'type chỉ nhận giá trị "gio" (theo khung giờ) hoặc "ngay" (theo ngày).'], 422);
            }

            $styles = $type === 'gio' ? 1 : 2;
        }

        $roomTypes = $query->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'slug', 'name', 'icon', 'icon_url', 'is_active', 'sort_order']);

        if ($categoryIds !== null && empty($categoryIds)) {
            $matchedProducts = collect();
        } else {
            $productsQuery = Product::where('is_activated', true)->whereNotNull('room_type_id');

            if ($styles !== null) {
                $productsQuery->where('styles', $styles);
            }
            if ($categoryIds !== null) {
                $productsQuery->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds));
            }

            $matchedProducts = $productsQuery->get(['id', 'room_type_id', 'styles', 'price']);
        }

        $productsByRoomType = $matchedProducts->groupBy('room_type_id');
        $firstSlotPriceMap  = $this->firstSlotPriceMap(
            $matchedProducts->filter(fn ($p) => (int) $p->styles === 1)->pluck('id')->all()
        );

        $data = $roomTypes->map(function (RoomType $rt) use ($productsByRoomType, $firstSlotPriceMap) {
            $products = $productsByRoomType->get($rt->id, collect());

            $prices = $products->map(fn ($p) => (int) $p->styles === 2
                ? ($p->price !== null ? (float) $p->price : null)
                : ($firstSlotPriceMap[$p->id] ?? null)
            )->filter(fn ($v) => $v !== null);

            return [
                'id'         => $rt->id,
                'slug'       => $rt->slug,
                'name'       => $rt->name,
                'icon'       => $rt->icon,
                'icon_url'   => $rt->icon_url,
                'is_active'  => (bool) $rt->is_active,
                'sort_order' => $rt->sort_order,
                'room_count' => $products->count(),
                'price'      => $prices->isNotEmpty() ? $prices->min() : null,
            ];
        });

        return response()->json(['data' => $data->values()]);
    }

    /**
     * GET /api/admin/room-types/{id}
     */
    public function show(int $id): JsonResponse
    {
        $roomType = RoomType::find($id);

        if (! $roomType) {
            return response()->json(['message' => 'Không tìm thấy danh mục phòng.'], 404);
        }

        return response()->json(['data' => $roomType]);
    }

    /**
     * POST /api/admin/room-types
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $roomType = RoomType::create($data);

        return response()->json(['data' => $roomType], 201);
    }

    /**
     * PUT|PATCH /api/admin/room-types/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $roomType = RoomType::find($id);

        if (! $roomType) {
            return response()->json(['message' => 'Không tìm thấy danh mục phòng.'], 404);
        }

        $data = $request->validate($this->rules(isUpdate: true, roomTypeId: $roomType->id));

        $roomType->update($data);

        return response()->json(['data' => $roomType->fresh()]);
    }

    /**
     * DELETE /api/admin/room-types/{id}
     * Chặn xoá nếu còn phòng nào đang gán danh mục này (products.room_type_id) — tránh mồ côi dữ
     * liệu, gỡ khỏi các phòng trước qua PATCH /api/admin/rooms/{id}/room-type (room_type_id=null).
     */
    public function destroy(int $id): JsonResponse
    {
        $roomType = RoomType::find($id);

        if (! $roomType) {
            return response()->json(['message' => 'Không tìm thấy danh mục phòng.'], 404);
        }

        if (Product::where('room_type_id', $id)->exists()) {
            return response()->json(['message' => 'Không thể xoá — đang có phòng gán danh mục này.'], 422);
        }

        $roomType->delete();

        return response()->json(['message' => 'Đã xoá danh mục phòng.']);
    }

    private function rules(bool $isUpdate = false, ?int $roomTypeId = null): array
    {
        $required = $isUpdate ? 'sometimes|required' : 'required';

        return [
            'name'       => "{$required}|string|max:255",
            'slug'       => [...explode('|', $required), 'string', 'max:255', Rule::unique('room_types', 'slug')->ignore($roomTypeId)],
            'icon'       => 'nullable|string|max:150',
            'icon_url'   => 'nullable|url|max:255',
            'sort_order' => 'nullable|integer|min:0',
            'is_active'  => 'nullable|boolean',
        ];
    }

    // Mở rộng 1 category (chi nhánh gốc HOẶC khu vực con) ra chính nó + toàn bộ danh mục con — giống
    // hệt ProductController::expandCategoryIds()/PromotionController::expandCategoryIds().
    private function expandCategoryIds(int $categoryId): array
    {
        $allIds       = [$categoryId];
        $currentLevel = [$categoryId];

        while (! empty($currentLevel)) {
            $children = Category::whereIn('parent_id', $currentLevel)->pluck('id')->toArray();
            $children = array_diff($children, $allIds);
            if (empty($children)) {
                break;
            }
            $allIds       = array_merge($allIds, $children);
            $currentLevel = $children;
        }

        return $allIds;
    }

    // Giá của khung giờ ĐẦU TIÊN trong ngày (start_time nhỏ nhất) cho từng phòng styles=1 trong
    // $productIds — 1 query DUY NHẤT cho toàn bộ danh sách thay vì lặp N+1 theo từng room_type.
    private function firstSlotPriceMap(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        return RoomTimeSlot::whereIn('room_time_slots.room_id', $productIds)
            ->whereNull('room_time_slots.date')
            ->whereNotNull('room_time_slots.price')
            ->join('time_slots', 'time_slots.id', '=', 'room_time_slots.timeslot_id')
            ->orderBy('time_slots.start_time')
            ->orderBy('room_time_slots.id')
            ->get(['room_time_slots.room_id as room_id', 'room_time_slots.price as price'])
            ->groupBy('room_id')
            ->map(fn ($rows) => (float) $rows->first()->price)
            ->all();
    }
}

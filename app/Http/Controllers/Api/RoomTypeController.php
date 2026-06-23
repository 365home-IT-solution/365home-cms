<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\BuildsRoomCard;
use App\Http\Concerns\ResolvesProvince;
use App\Http\Controllers\Controller;
use App\Models\Province;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomType;

class RoomTypeController extends Controller
{
    use BuildsRoomCard, ResolvesProvince;

    // GET /api/room-types
    public function index(): JsonResponse
    {
        $roomTypes = RoomType::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'slug', 'name', 'icon', 'icon_url', 'is_active', 'sort_order']);

        return response()->json($roomTypes);
    }

    // GET /api/v1/room-types/{id}
    // ?province_id={id} → override tỉnh (dùng cho guest)
    // Auth customer → tự lấy province_id từ profile
    public function show(int $id, Request $request): JsonResponse
    {
        $roomType = RoomType::where('id', $id)->where('is_active', true)->first();

        if (! $roomType) {
            return response()->json(['message' => 'Không tìm thấy loại phòng.'], 404);
        }

        $province = $this->resolveProvince($request);

        $authUser      = auth('sanctum')->user();
        $wishlistedIds = $authUser
            ? $authUser->wishlists()->pluck('product_id')->toArray()
            : null;

        $branches = $province
            ? $this->branchesWithProvince($roomType->id, $province, $wishlistedIds)
            : $this->branchesWithoutProvince($roomType->id, $wishlistedIds);

        return response()->json([
            'room_type' => [
                'id'       => $roomType->id,
                'name'     => $roomType->name,
                'slug'     => $roomType->slug,
                'branches' => $branches,
            ],
        ]);
    }

    // Có province: lấy branches của tỉnh đó, lọc phòng theo room_type trong từng branch
    private function branchesWithProvince(int $roomTypeId, Province $province, ?array $wishlistedIds): array
    {
        return $province->branches()
            ->where('status', true)
            ->with('category')
            ->get()
            ->map(function ($branch) use ($roomTypeId, $wishlistedIds) {
                $category = $branch->category;
                $childIds = Category::where('parent_id', $category->id)->pluck('id')->toArray();
                $allIds   = array_merge([$category->id], $childIds);

                $rooms = Product::where('room_type_id', $roomTypeId)
                    ->where('is_activated', true)
                    ->where('is_in_stock', true)
                    ->whereHas('categories', fn ($q) => $q->whereIn('category_id', $allIds))
                    ->with(['roomType', 'media', 'roomTimeSlots.timeSlot', 'roomTimeSlots.promotions'])
                    ->orderBy('sort_order')
                    ->get()
                    ->map(function ($room) use ($wishlistedIds) {
                        $status = $wishlistedIds === null ? null : in_array($room->id, $wishlistedIds);
                        return $this->mapRoom($room, $status);
                    })
                    ->values();

                if ($rooms->isEmpty()) {
                    return null;
                }

                return [
                    'id'          => $category->id,
                    'name'        => $category->name,
                    'slug'        => $category->slug,
                    'description' => $category->description,
                    'image_url'   => $category->image
                        ? Storage::disk('public')->url($category->image)
                        : null,
                    'items'       => $rooms,
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }

    // Không có province: hiển thị tất cả phòng, group theo parent category
    private function branchesWithoutProvince(int $roomTypeId, ?array $wishlistedIds): array
    {
        $products = Product::where('room_type_id', $roomTypeId)
            ->where('is_activated', true)
            ->where('is_in_stock', true)
            ->with([
                'roomType',
                'media',
                'roomTimeSlots.timeSlot',
                'roomTimeSlots.promotions',
                'categories' => fn ($q) => $q->whereNull('parent_id'),
            ])
            ->orderBy('sort_order')
            ->get();

        $parentCategoryIds = $products
            ->flatMap(fn ($p) => $p->categories->pluck('id'))
            ->unique()
            ->toArray();

        $categoriesById = Category::whereIn('id', $parentCategoryIds)
            ->get()
            ->keyBy('id');

        return $products
            ->groupBy(fn ($room) => $room->categories->first()?->id)
            ->map(function ($rooms, $categoryId) use ($categoriesById, $wishlistedIds) {
                $category = $categoriesById->get($categoryId);

                return [
                    'id'          => $category?->id,
                    'name'        => $category?->name,
                    'slug'        => $category?->slug,
                    'description' => $category?->description,
                    'image_url'   => $category?->image
                        ? Storage::disk('public')->url($category->image)
                        : null,
                    'items'       => $rooms->map(function ($room) use ($wishlistedIds) {
                        $status = $wishlistedIds === null ? null : in_array($room->id, $wishlistedIds);
                        return $this->mapRoom($room, $status);
                    })->values(),
                ];
            })
            ->values()
            ->toArray();
    }
}

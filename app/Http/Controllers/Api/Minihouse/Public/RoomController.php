<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Minihouse\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Models\BuildingSetting;
use Modules\Minihouse\App\Models\Room;

// Danh sách/chi tiết PHÒNG CÒN TRỐNG, CÔNG KHAI — trang tìm phòng chính của khách chưa thuê. Chỉ
// trả về phòng thuộc toà đang bật "status" VÀ chính phòng đó đang "Trống" (Room::scopeAvailable()).
class RoomController extends Controller
{
    /**
     * GET /api/minihouse/public/rooms
     * Query params: zone_id, building_id, min_price, max_price, min_area, max_area,
     *               amenity_ids[] (VD amenity_ids[]=1&amenity_ids[]=2 — phòng phải có ĐỦ mọi tiện ích),
     *               search (mã phòng/tên hoặc địa chỉ toà nhà), sort (price_asc|price_desc|area_asc|area_desc,
     *               mặc định price_asc), per_page (mặc định 12)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Room::query()
            ->available()
            ->whereHas('building', fn ($q) => $q->where('status', true))
            // KHÔNG giới hạn cột qua 'building:id,name,...' — "address"/"zone_id" là thuộc tính ẢO
            // (uỷ quyền qua BuildingSetting, xem Building.php), không phải cột thật trên bảng
            // categories; cú pháp "quan hệ:cột" của Eloquent SELECT thẳng tên cột đó ở SQL nên báo
            // lỗi "Unknown column" nếu dùng — phải tải nguyên Building rồi để accessor tự tính.
            ->with(['building', 'amenities:id,name,image']);

        if ($request->filled('building_id')) {
            $query->where('building_id', $request->string('building_id'));
        }

        if ($request->filled('zone_id')) {
            $buildingIds = BuildingSetting::where('zone_id', $request->integer('zone_id'))->pluck('category_id');
            $query->whereIn('building_id', $buildingIds);
        }

        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->float('min_price'));
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->float('max_price'));
        }

        // "area" (diện tích) là cột THẬT trên products (room_area_sqm) — KHÔNG qua RoomDetail, lọc
        // trực tiếp được, không cần whereHas.
        if ($request->filled('min_area')) {
            $query->where('room_area_sqm', '>=', $request->float('min_area'));
        }
        if ($request->filled('max_area')) {
            $query->where('room_area_sqm', '<=', $request->float('max_area'));
        }

        if ($request->filled('amenity_ids')) {
            foreach ((array) $request->input('amenity_ids') as $amenityId) {
                $query->whereHas('amenities', fn ($q) => $q->where('minihouse_amenities.id', $amenityId));
            }
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('building', fn ($q2) => $q2->where('name', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%"));
            });
        }

        match ($request->string('sort', 'price_asc')->toString()) {
            'price_desc' => $query->orderByDesc('price'),
            'area_asc'   => $query->orderBy('room_area_sqm'),
            'area_desc'  => $query->orderByDesc('room_area_sqm'),
            default      => $query->orderBy('price'),
        };

        $rooms = $query->paginate($request->integer('per_page', 12));

        $rooms->getCollection()->transform(fn (Room $room) => $this->transformSummary($room));

        return response()->json($rooms);
    }

    /**
     * GET /api/minihouse/public/rooms/{id}
     */
    public function show(string $id): JsonResponse
    {
        $room = Room::query()
            ->available()
            ->whereHas('building', fn ($q) => $q->where('status', true))
            ->with(['building.zone:id,name', 'amenities:id,name,image'])
            ->find($id);

        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng, hoặc phòng đã được cho thuê.'], 404);
        }

        $scenes = $room->panoramaScenes()->where('is_published', true)->get();
        $buildingScenes = $room->building->panoramaScenes()->where('is_published', true)->exists();

        return response()->json(['data' => [
            'id'          => $room->id,
            'code'        => $room->code,
            'area'        => $room->area,
            'price'       => $room->price,
            'floor'       => $room->floor,
            'note'        => $room->note,
            'photos'      => $room->photos ?? [],
            'building'    => [
                'id'      => $room->building->id,
                'name'    => $room->building->name,
                'address' => $room->building->address,
                'province' => $room->building->province,
                'ward'    => $room->building->ward,
                'zone'    => $room->building->zone ? ['id' => $room->building->zone->id, 'name' => $room->building->zone->name] : null,
            ],
            'amenities'   => $room->amenities->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'image' => $a->image]),
            'tour_url'    => $scenes->isNotEmpty()
                ? route('minihouse.tour.scene', [$room->building_id, $scenes->first()->id])
                : ($buildingScenes ? route('minihouse.tour.show', $room->building_id) : null),
        ]]);
    }

    private function transformSummary(Room $room): array
    {
        $photos = $room->photos ?? [];

        return [
            'id'       => $room->id,
            'code'     => $room->code,
            'area'     => $room->area,
            'price'    => $room->price,
            'floor'    => $room->floor,
            'cover'    => $photos[0] ?? null,
            'building' => $room->building ? [
                'id'      => $room->building->id,
                'name'    => $room->building->name,
                'address' => $room->building->address,
            ] : null,
            'amenities' => $room->amenities->map(fn ($a) => ['id' => $a->id, 'name' => $a->name]),
        ];
    }
}

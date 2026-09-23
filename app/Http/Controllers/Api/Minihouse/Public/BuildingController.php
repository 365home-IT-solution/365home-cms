<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Minihouse\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\BuildingSetting;
use Modules\Minihouse\App\Models\Room;

// Danh sách/chi tiết Toà nhà CÔNG KHAI (trang tìm phòng chưa đăng nhập) — CHỈ hiện toà đang bật
// "status" VÀ đang có ÍT NHẤT 1 phòng trống, KHÔNG lộ bất kỳ trường nội bộ nào của Building (chủ nhà,
// tài khoản ngân hàng, khoá API cổng thanh toán... — xem $fillable đầy đủ ở Building.php, hầu hết
// KHÔNG được đưa vào response ở đây, chỉ chọn lọc đúng field cần cho khách xem phòng).
class BuildingController extends Controller
{
    /**
     * GET /api/minihouse/public/buildings
     * Query params: zone_id, search (tên/địa chỉ), per_page (mặc định 12)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Building::query()
            ->where('status', true)
            ->whereHas('rooms', fn ($q) => $q->available())
            ->with('zone:id,name');

        if ($request->filled('zone_id')) {
            $query->whereIn('id', BuildingSetting::where('zone_id', $request->integer('zone_id'))->pluck('category_id'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $buildings = $query->orderBy('name')->paginate($request->integer('per_page', 12));

        $buildings->getCollection()->transform(fn (Building $building) => $this->transform($building));

        return response()->json($buildings);
    }

    /**
     * GET /api/minihouse/public/buildings/{id}
     */
    public function show(int $id): JsonResponse
    {
        $building = Building::query()
            ->where('status', true)
            ->with('zone:id,name')
            ->find($id);

        if (! $building) {
            return response()->json(['message' => 'Không tìm thấy toà nhà.'], 404);
        }

        $data = $this->transform($building);

        $rooms = $building->rooms()->available()
            ->with('amenities:id,name,image')
            ->get()
            ->map(fn ($room) => $this->transformRoomSummary($room));

        $data['rooms'] = $rooms;

        return response()->json(['data' => $data]);
    }

    private function transform(Building $building): array
    {
        $availableRooms = $building->rooms()->available()->get(['products.id', 'products.price']);

        // Tour ảo 360° (nếu toà đã có ít nhất 1 điểm đứng đã publish) — trỏ THẲNG tới trang web
        // công khai có sẵn (PanoramaTourController), không cần dựng lại dữ liệu tour riêng cho app.
        $hasTour = $building->panoramaScenes()->where('is_published', true)->exists();

        return [
            'id'                    => $building->id,
            'name'                  => $building->name,
            'address'               => $building->address,
            'province'              => $building->province,
            'ward'                  => $building->ward,
            'image'                 => $building->image,
            'zone'                  => $building->zone ? ['id' => $building->zone->id, 'name' => $building->zone->name] : null,
            'available_rooms_count' => $availableRooms->count(),
            'price_from'            => $availableRooms->min('price'),
            'tour_url'              => $hasTour ? route('minihouse.tour.show', $building->id) : null,
            'floorplan_url'         => $hasTour ? route('minihouse.tour.floorplan', $building->id) : null,
        ];
    }

    private function transformRoomSummary(Room $room): array
    {
        $photos = $room->photos ?? [];

        return [
            'id'     => $room->id,
            'code'   => $room->code,
            'area'   => $room->area,
            'price'  => $room->price,
            'floor'  => $room->floor,
            'cover'  => $photos[0] ?? null,
            'amenities' => $room->amenities->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'image' => $a->image]),
        ];
    }
}

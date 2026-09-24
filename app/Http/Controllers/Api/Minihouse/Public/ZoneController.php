<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Minihouse\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\BuildingSetting;
use Modules\Minihouse\App\Models\Zone;

// Danh sách khu vực CÔNG KHAI (trang tìm phòng chưa đăng nhập) — CHỈ liệt kê khu vực có ÍT NHẤT 1
// toà nhà đang bật "status" (xem Building/Category::$casts) VÀ toà đó có ÍT NHẤT 1 phòng còn trống,
// để không hiện khu vực "rỗng" (chọn vào cũng không có gì để xem) trên bộ lọc.
//
// Zone::buildings() KHÔNG PHẢI hasMany() chuẩn (zone_id là cột ảo, uỷ quyền qua BuildingSetting —
// xem Zone.php) nên không dùng ->withCount() được, phải tự đếm bằng subquery riêng.
class ZoneController extends Controller
{
    public function index(): JsonResponse
    {
        $zoneIdsWithAvailableRooms = BuildingSetting::query()
            ->whereIn('category_id', Building::query()
                ->where('status', true)
                ->whereHas('rooms', fn ($q) => $q->available())
                ->pluck('id'))
            ->whereNotNull('zone_id')
            ->pluck('zone_id')
            ->unique();

        $zones = Zone::query()
            ->whereIn('id', $zoneIdsWithAvailableRooms)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Zone $zone) => [
                'id'   => $zone->id,
                'name' => $zone->name,
            ]);

        return response()->json(['data' => $zones]);
    }
}

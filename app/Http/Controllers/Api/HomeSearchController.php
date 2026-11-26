<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HomeSearch\HomeRoomSearch;
use App\Services\HomeSearch\HomeSearchDestinations;
use App\Services\HomeSearch\OldProvinceDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ô tìm kiếm trang chủ mobile (kiểu Agoda): "Bạn muốn đi đâu?" → danh sách tỉnh/thành CŨ → kết quả
 * là danh sách card phòng (khuyến mãi lên đầu), không bản đồ/lịch/khung giờ.
 *
 * GET /api/v1/search/destinations → 63 tỉnh/thành cũ + thành phố thuộc tỉnh, kèm room_count.
 * GET /api/v1/search/home-rooms   → card phòng theo điểm đến + ngày nhận/trả + số khách.
 */
class HomeSearchController extends Controller
{
    public function destinations(HomeSearchDestinations $destinations): JsonResponse
    {
        return response()->json(['data' => $destinations->all()])
            ->header('Cache-Control', 'public, max-age=300');
    }

    public function rooms(Request $request, HomeSearchDestinations $destinations, HomeRoomSearch $search): JsonResponse
    {
        $validated = $request->validate([
            'dest_kind' => ['nullable', 'in:province,city'],
            'dest_code' => ['nullable', 'integer', 'required_with:dest_kind'],
            // Slug chi nhánh (gợi ý "cơ sở lưu trú" từ /v1/search/locations).
            'province'  => ['nullable', 'string', 'max:255'],
            'lat'       => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng'       => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius'    => ['nullable', 'numeric', 'min:1', 'max:50'],
            'checkin'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'checkout'  => ['nullable', 'date_format:Y-m-d', 'after:checkin'],
            'guests'    => ['nullable', 'integer', 'min:1', 'max:50'],
            'type'      => ['nullable', 'string', 'max:50'],
            'sort'      => ['nullable', 'in:' . implode(',', HomeRoomSearch::SORTS)],
            'page'      => ['nullable', 'integer', 'min:1'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $onlyIds         = null;
        $destinationName = null;

        if (! empty($validated['dest_kind'])) {
            $destination = $destinations->find($validated['dest_kind'], (int) $validated['dest_code']);
            if ($destination === null) {
                return response()->json(['message' => 'Không tìm thấy điểm đến.'], 404);
            }

            $onlyIds         = $destinations->roomIdsFor($destination);
            $destinationName = OldProvinceDirectory::shortName($destination['name']);
        }

        $result = $search->run($validated, $onlyIds, auth('sanctum')->user());
        $result['meta']['destination_name'] = $destinationName;

        return response()->json($result);
    }
}

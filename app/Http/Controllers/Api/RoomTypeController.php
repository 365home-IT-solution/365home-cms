<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Product\App\Models\RoomType;

class RoomTypeController extends Controller
{
    /**
     * GET /api/room-types
     * Trả danh sách danh mục phòng đang kích hoạt, dùng cho select/dropdown.
     */
    public function index(): JsonResponse
    {
        $roomTypes = RoomType::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'slug', 'name', 'icon', 'icon_url', 'is_active', 'sort_order']);

        return response()->json($roomTypes);
    }
}

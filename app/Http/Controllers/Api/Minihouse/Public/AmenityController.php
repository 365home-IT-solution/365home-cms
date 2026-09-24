<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Minihouse\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Minihouse\App\Models\Amenity;

// Danh sách tiện ích CÔNG KHAI — dùng để dựng bộ lọc "Tiện ích" trên trang tìm phòng.
class AmenityController extends Controller
{
    public function index(): JsonResponse
    {
        $amenities = Amenity::query()
            ->orderBy('name')
            ->get(['id', 'name', 'image']);

        return response()->json(['data' => $amenities]);
    }
}

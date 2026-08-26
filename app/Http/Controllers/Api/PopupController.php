<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\AppPage\App\Models\PopupImage;

class PopupController extends Controller
{
    /**
     * GET /api/v1/popups
     * Danh sách ảnh popup theo đúng thứ tự đã sắp xếp trên admin — dùng cho cả app và website.
     */
    public function index(): JsonResponse
    {
        $items = PopupImage::orderBy('sort_order')
            ->get()
            ->map(fn (PopupImage $item) => [
                'id'        => $item->id,
                'image_url' => $item->image_url,
                'thumbnail' => $item->thumbnail,
                'url'       => $item->url,
                'sort_order' => $item->sort_order,
            ])
            ->values();

        return response()->json(['data' => $items]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Spatie\Tags\Tag;

/**
 * Danh sách TIỆN ÍCH phòng — dùng bảng `tags` (Spatie\Tags\Tag, field `tags` trên ProductForm, label
 * hiển thị "Tiện ích" — xem Modules/Product/lang/vi/product.php). KHÔNG phải RoomAmenity (module
 * riêng "room_amenities", dùng cho 1 màn quản lý khác - RoomAmenityAssignResource).
 *
 * name/slug là field translatable (spatie/laravel-translatable) nên đọc thẳng $tag->name là ra
 * chuỗi theo locale hiện tại. Riêng `image` KHÔNG được khai báo translatable trên model Tag của
 * package, nhưng TagForm (Filament) vẫn cố tình lưu dạng JSON {"vi": "path"} giống name/slug (xem
 * TagForm::imagesCard dehydrateStateUsing) — nên phải tự giải mã ở đây thay vì đọc thẳng.
 */
class TagController extends Controller
{
    /**
     * GET /api/admin/tags
     * Query params: search (lọc theo tên).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Tag::query();

        if ($request->filled('search')) {
            $search = (string) $request->string('search');
            $query->where('name', 'like', "%{$search}%");
        }

        $tags = $query->orderBy('order_column')->orderBy('id')->get();

        $data = $tags->map(fn (Tag $tag) => [
            'id'        => $tag->id,
            'name'      => $tag->name,
            'image'     => $image = $this->extractImage($tag),
            'image_url' => $image ? Storage::disk('public')->url($image) : null,
        ])->values();

        return response()->json(['data' => $data]);
    }

    private function extractImage(Tag $tag): ?string
    {
        $raw = $tag->getRawOriginal('image');

        if (empty($raw)) {
            return null;
        }

        if (is_string($raw) && str_starts_with($raw, '{')) {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                return $decoded[app()->getLocale()] ?? collect($decoded)->first();
            }
        }

        return $raw;
    }
}

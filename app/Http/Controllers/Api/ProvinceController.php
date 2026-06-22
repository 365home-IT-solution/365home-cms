<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Province;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

class ProvinceController extends Controller
{
    // ─── GET /api/v1/provinces ───────────────────────────────────────────────
    // Trả về danh sách tỉnh/thành phố theo nhóm:
    //   - "Phổ biến": Hà Nội + Hồ Chí Minh
    //   - Còn lại: nhóm theo ký tự đầu (sắp xếp bảng chữ cái)

    public function index(): JsonResponse
    {
        $provinces = Province::orderBy('name')->get();

        $popular = $provinces->filter(
            fn ($p) => str_contains($p->slug, 'ha-noi') || str_contains($p->slug, 'ho-chi-minh')
        );

        $others = $provinces->reject(
            fn ($p) => str_contains($p->slug, 'ha-noi') || str_contains($p->slug, 'ho-chi-minh')
        );

        $grouped = $others
            ->groupBy(fn ($p) => mb_strtoupper(mb_substr($p->name, 0, 1)))
            ->sortKeys()
            ->map(fn ($items) => $this->mapProvinceItems($items));

        $sections = collect();

        if ($popular->isNotEmpty()) {
            $sections->push([
                'group' => 'Phổ biến',
                'items' => $this->mapProvinceItems($popular),
            ]);
        }

        foreach ($grouped as $letter => $items) {
            $sections->push([
                'group' => $letter,
                'items' => $items,
            ]);
        }

        return response()->json(['provinces' => $sections->values()]);
    }

    // ─── GET /api/v1/provinces/detect?lat=...&lng=... ────────────────────────
    // Tìm tỉnh/thành phố gần nhất dựa trên tọa độ GPS.
    // Yêu cầu province có lat/lng được khai báo trong admin.

    public function detect(Request $request): JsonResponse
    {
        $lat = (float) $request->query('lat', 0);
        $lng = (float) $request->query('lng', 0);

        if ($lat === 0.0 && $lng === 0.0) {
            return response()->json(['message' => 'Thiếu tọa độ lat/lng.'], 422);
        }

        $provinces = Province::whereNotNull('lat')
            ->whereNotNull('lng')
            ->get();

        if ($provinces->isEmpty()) {
            return response()->json(['province' => null]);
        }

        $nearest = $provinces->sortBy(
            fn ($p) => $this->haversine($lat, $lng, (float) $p->lat, (float) $p->lng)
        )->first();

        return response()->json([
            'province' => [
                'id'        => $nearest->id,
                'name'      => $nearest->name,
                'slug'      => $nearest->slug,
                'image_url' => $nearest->image
                    ? Storage::disk('public')->url($nearest->image)
                    : null,
            ],
        ]);
    }

    // ─── GET /api/v1/provinces/{id}/branches ─────────────────────────────────
    // Trả về danh sách chi nhánh của tỉnh/thành phố theo ID,
    // kèm total_room (số phòng active trong chi nhánh đó).

    public function branches(int $id): JsonResponse
    {
        $province = Province::find($id);

        if (! $province) {
            return response()->json(['message' => 'Không tìm thấy tỉnh/thành phố.'], 404);
        }

        $branches = $province->branches()
            ->where('status', true)
            ->with('category')
            ->get()
            ->map(function ($branch) {
                $categoryId = $branch->categorie_id;

                $childIds = Category::where('parent_id', $categoryId)
                    ->pluck('id')
                    ->toArray();

                $allIds = array_merge([$categoryId], $childIds);

                $totalRoom = Product::where('is_activated', true)
                    ->where('is_in_stock', true)
                    ->whereHas('categories', fn ($q) => $q->whereIn('category_id', $allIds))
                    ->count();

                return [
                    'id'         => $branch->category->id,
                    'name'       => $branch->category->name,
                    'slug'       => $branch->category->slug,
                    'image_url'  => $branch->category->image
                        ? Storage::disk('public')->url($branch->category->image)
                        : null,
                    'total_room' => $totalRoom,
                ];
            })
            ->values()
            ->toArray();

        return response()->json([
            'province' => [
                'id'        => $province->id,
                'name'      => $province->name,
                'slug'      => $province->slug,
                'image_url' => $province->image
                    ? Storage::disk('public')->url($province->image)
                    : null,
            ],
            'branches' => $branches,
        ]);
    }

    // ─── GET /api/v1/provinces/{slug} ────────────────────────────────────────
    // Endpoint cũ: giữ nguyên

    public function show(string $slug): JsonResponse
    {
        $province = Province::where('slug', $slug)->first();

        if (! $province) {
            return response()->json(['message' => 'Province not found.'], 404);
        }

        $branches = $province->branches()
            ->where('status', true)
            ->with('category')
            ->get()
            ->map(fn ($branch) => [
                'id'          => $branch->category->id,
                'name'        => $branch->category->name,
                'slug'        => $branch->category->slug,
                'description' => $branch->category->description,
                'image_url'   => $branch->category->image
                    ? Storage::disk('public')->url($branch->category->image)
                    : null,
            ])
            ->values()
            ->toArray();

        return response()->json([
            'province' => [
                'id'        => $province->id,
                'name'      => $province->name,
                'slug'      => $province->slug,
                'image_url' => $province->image
                    ? Storage::disk('public')->url($province->image)
                    : null,
                'branches'  => $branches,
            ],
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function mapProvinceItems($provinces): array
    {
        return $provinces->map(fn ($p) => [
            'id'        => $p->id,
            'name'      => $p->name,
            'slug'      => $p->slug,
            'image_url' => $p->image
                ? Storage::disk('public')->url($p->image)
                : null,
        ])->values()->toArray();
    }

    // Khoảng cách Haversine (km) giữa hai tọa độ
    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

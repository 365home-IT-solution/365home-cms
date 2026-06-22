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
    private const POPULAR_LIMIT = 5;

    // ─── GET /api/v1/provinces ───────────────────────────────────────────────

    public function index(): JsonResponse
    {
        $provinces = Province::with(['branches' => fn ($q) => $q->where('status', true)])
            ->orderBy('name')
            ->get();

        // Batch: lấy tất cả child category ID trong 1 query
        $allBranchCategoryIds = $provinces
            ->flatMap(fn ($p) => $p->branches->pluck('categorie_id'))
            ->unique()
            ->filter()
            ->values()
            ->toArray();

        $childIdsByParent = Category::whereIn('parent_id', $allBranchCategoryIds)
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id')
            ->map(fn ($g) => $g->pluck('id')->toArray());

        // Đếm phòng cho từng tỉnh
        $provinces->each(function ($province) use ($childIdsByParent) {
            $categoryIds = $province->branches->pluck('categorie_id')->filter()->toArray();

            if (empty($categoryIds)) {
                $province->room_count = 0;

                return;
            }

            $allIds = collect($categoryIds)
                ->flatMap(fn ($id) => array_merge([$id], $childIdsByParent->get($id, [])))
                ->unique()
                ->values()
                ->toArray();

            $province->room_count = Product::where('is_activated', true)
                ->where('is_in_stock', true)
                ->whereHas('categories', fn ($q) => $q->whereIn('category_id', $allIds))
                ->count();
        });

        // Top POPULAR_LIMIT tỉnh có nhiều phòng nhất → nhóm "Phổ biến"
        $popular = $provinces
            ->filter(fn ($p) => $p->room_count > 0)
            ->sortByDesc('room_count')
            ->take(self::POPULAR_LIMIT);

        $popularIds = $popular->pluck('id')->toArray();

        // Còn lại → nhóm theo ký tự đầu của tên thực (bỏ prefix Tỉnh/Thành phố)
        $grouped = $provinces
            ->reject(fn ($p) => in_array($p->id, $popularIds))
            ->groupBy(function ($p) {
                $base = preg_replace('/^(Thành phố|Tỉnh)\s+/u', '', $p->name);
                return mb_strtoupper(mb_substr($base, 0, 1));
            })
            ->sortKeys()
            ->map(fn ($items) => $this->mapItems($items));

        $sections = collect();

        if ($popular->isNotEmpty()) {
            $sections->push([
                'group' => 'Phổ biến',
                'items' => $this->mapItems($popular),
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

    public function detect(Request $request): JsonResponse
    {
        $lat = (float) $request->query('lat', 0);
        $lng = (float) $request->query('lng', 0);

        if ($lat === 0.0 && $lng === 0.0) {
            return response()->json(['message' => 'Thiếu tọa độ lat/lng.'], 422);
        }

        $provinces = Province::whereNotNull('lat')->whereNotNull('lng')->get();

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

    // ─── GET /api/v1/provinces/{slug} ────────────────────────────────────────

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
            ->map(function ($branch) {
                $categoryId = $branch->categorie_id;
                $childIds   = Category::where('parent_id', $categoryId)->pluck('id')->toArray();
                $allIds     = array_merge([$categoryId], $childIds);

                $totalRoom = Product::where('is_activated', true)
                    ->where('is_in_stock', true)
                    ->whereHas('categories', fn ($q) => $q->whereIn('category_id', $allIds))
                    ->count();

                return [
                    'id'          => $branch->category->id,
                    'name'        => $branch->category->name,
                    'slug'        => $branch->category->slug,
                    'description' => $branch->category->description,
                    'image_url'   => $branch->category->image
                        ? Storage::disk('public')->url($branch->category->image)
                        : null,
                    'total_room'  => $totalRoom,
                ];
            })
            ->values()
            ->toArray();

        return response()->json([
            'province' => [
                'id'       => $province->id,
                'name'     => $province->name,
                'slug'     => $province->slug,
                'branches' => $branches,
            ],
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function mapItems($provinces): array
    {
        return $provinces->map(fn ($p) => [
            'id'   => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
        ])->values()->toArray();
    }

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

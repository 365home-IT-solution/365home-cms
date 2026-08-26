<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\GuestCustomer;
use App\Models\Province;
use App\Models\ProvinceBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

class ProvinceController extends Controller
{
    // ─── GET /api/v1/provinces ───────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        // ?filter=all → trả về tất cả tỉnh/thành, bỏ qua điều kiện "có phòng".
        if ($request->query('filter') === 'all') {
            $provinces = Province::orderByRaw('code IS NULL, code ASC')
                ->orderBy('name')
                ->get();

            return response()->json([
                'provinces' => $provinces->map(fn ($p) => [
                    'id'            => $p->id,
                    'name'          => $p->name,
                    'slug'          => $p->slug,
                    'code'          => $p->code,
                    'division_type' => $p->division_type,
                    'codename'      => $p->codename,
                ])->values(),
            ]);
        }

        // Chỉ hiển thị tỉnh/thành có ít nhất 1 chi nhánh đang hoạt động (province_branches.status)
        // và chi nhánh đó có ít nhất 1 phòng đang bán (is_activated + is_in_stock) — cùng logic
        // lọc "chi nhánh có phòng" đang dùng ở RoomSearchService/RoomTypeController.
        $activeBranches = ProvinceBranch::where('status', true)->get(['province_id', 'categorie_id']);

        $branchCategoryIds = $activeBranches->pluck('categorie_id')->unique()->values();

        // ProvinceBranch.status chỉ nói tỉnh này có bật chi nhánh không — chi nhánh (Category gốc)
        // còn phải tự nó đang active thì mới cho hiển thị.
        $activeBranchCategoryIds = Category::whereIn('id', $branchCategoryIds)
            ->where('status', true)
            ->pluck('id');

        $activeBranches = $activeBranches->filter(
            fn (ProvinceBranch $branch) => $activeBranchCategoryIds->contains($branch->categorie_id)
        );

        $childCategoriesByParent = Category::whereIn('parent_id', $activeBranchCategoryIds)
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id');

        $provinceIdsWithRooms = $activeBranches
            ->filter(function (ProvinceBranch $branch) use ($childCategoriesByParent) {
                $categoryIds = collect([$branch->categorie_id])
                    ->merge($childCategoriesByParent->get($branch->categorie_id, collect())->pluck('id'));

                return Product::where('is_activated', true)
                    ->where('is_in_stock', true)
                    ->whereHas('categories', fn ($q) => $q->whereIn('category_id', $categoryIds))
                    ->exists();
            })
            ->pluck('province_id')
            ->unique();

        $provinces = Province::whereIn('id', $provinceIdsWithRooms)
            ->orderByRaw('code IS NULL, code ASC')
            ->orderBy('name')
            ->get();

        return response()->json([
            'provinces' => $provinces->map(fn ($p) => [
                'id'            => $p->id,
                'name'          => $p->name,
                'slug'          => $p->slug,
                'code'          => $p->code,
                'division_type' => $p->division_type,
                'codename'      => $p->codename,
            ])->values(),
        ]);
    }

    // ─── POST /api/v1/provinces/select ──────────────────────────────────────
    // Dùng chung cho cả customer (có token) lẫn guest (dùng device_token)

    public function getSelected(Request $request): JsonResponse|\Illuminate\Http\Response
    {
        $authUser = auth('sanctum')->user();

        if ($authUser) {
            $province = $authUser->province_id
                ? Province::find($authUser->province_id)
                : null;
        } else {
            $deviceToken = $request->query('device_token');
            $province    = null;
            if ($deviceToken) {
                $guest    = GuestCustomer::where('device_token', $deviceToken)->first();
                $province = $guest?->province_id ? Province::find($guest->province_id) : null;
            }
        }

        if (! $province) {
            return response()->noContent();
        }

        return response()->json([
            'id'            => $province->id,
            'name'          => $province->name,
            'slug'          => $province->slug,
            'code'          => $province->code,
            'division_type' => $province->division_type,
            'codename'      => $province->codename,
        ]);
    }

    public function showInfo(int $id): JsonResponse|\Illuminate\Http\Response
    {
        $province = Province::find($id);

        if (! $province) {
            return response()->noContent(404);
        }

        return response()->json([
            'id'            => $province->id,
            'name'          => $province->name,
            'slug'          => $province->slug,
            'code'          => $province->code,
            'division_type' => $province->division_type,
            'codename'      => $province->codename,
        ]);
    }

    public function select(Request $request): \Illuminate\Http\Response
    {
        $authUser = auth('sanctum')->user();

        $rules = ['province_id' => 'required|integer|exists:provinces,id'];
        if (! $authUser) {
            $rules['device_token'] = 'required|string|max:255';
        }
        $request->validate($rules);

        if ($authUser) {
            $authUser->update(['province_id' => $request->province_id]);
        } else {
            GuestCustomer::findOrCreateByDevice(
                deviceToken: $request->device_token,
                attributes: ['province_id' => $request->province_id],
            );
        }

        return response('', 200);
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
                'thumbnail' => $nearest->thumbnail,
            ],
        ]);
    }

    // ─── GET /api/v1/provinces/{slug} ────────────────────────────────────────

    public function show(int $id): JsonResponse
    {
        $province = Province::find($id);

        if (! $province) {
            return response()->json(['message' => 'Province not found.'], 404);
        }

        $wards = $province->branches()
            ->where('status', true)
            ->whereNotNull('ward_code')
            ->with(['ward', 'category'])
            ->get()
            ->filter(fn ($branch) => $branch->category && $branch->category->status)
            ->groupBy('ward_code')
            ->map(fn ($items, $wardCode) => [
                'code'           => (int) $wardCode,
                'name'           => $items->first()->ward?->name,
                'total_branches' => $items->count(),
            ])
            ->sortBy('name')
            ->values();

        return response()->json([
            'province' => [
                'id'    => $province->id,
                'name'  => $province->name,
                'slug'  => $province->slug,
                'wards' => $wards,
            ],
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

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

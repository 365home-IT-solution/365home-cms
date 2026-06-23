<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\GuestCustomer;
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

    public function index(): JsonResponse
    {
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

        $branches = $province->branches()
            ->where('status', true)
            ->with(['category', 'ward'])
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
                    'ward_code'   => $branch->ward_code,
                    'ward_name'   => $branch->ward?->name,
                    'id'          => $branch->category->id,
                    'name'        => $branch->category->name,
                    'slug'        => $branch->category->slug,
                    'description' => $branch->category->description,
                    'image_url'   => $branch->category->image
                        ? Storage::disk('public')->url($branch->category->image)
                        : null,
                    'total_room'  => $totalRoom,
                ];
            });

        $grouped = $branches
            ->groupBy('ward_code')
            ->map(fn ($items, $wardCode) => [
                'ward'  => $wardCode ? [
                    'code' => (int) $wardCode,
                    'name' => $items->first()['ward_name'],
                ] : null,
                'items' => $items->map(fn ($b) => [
                    'id'          => $b['id'],
                    'name'        => $b['name'],
                    'slug'        => $b['slug'],
                    'description' => $b['description'],
                    'image_url'   => $b['image_url'],
                    'total_room'  => $b['total_room'],
                ])->values(),
            ])
            ->sortBy(fn ($group) => $group['ward'] === null ? 1 : 0)
            ->values();

        return response()->json([
            'province' => [
                'id'       => $province->id,
                'name'     => $province->name,
                'slug'     => $province->slug,
                'branches' => $grouped,
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

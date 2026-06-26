<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\BuildsRoomCard;
use App\Models\ProvinceBranch;
use App\Models\Ward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

class WardController extends Controller
{
    use BuildsRoomCard;

    // GET /api/v2/ward
    // ?province_code=92  → lọc theo tỉnh
    // ?search=bình       → tìm theo tên
    // ?division_type=phường → lọc loại đơn vị
    public function index(Request $request): JsonResponse
    {
        $query = Ward::query();

        if ($provinceCode = $request->integer('province_code')) {
            $query->where('province_code', $provinceCode);
        }

        if ($search = $request->string('search')->trim()->value()) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($type = $request->string('division_type')->trim()->value()) {
            $query->where('division_type', $type);
        }

        $wards = $query->orderBy('province_code')->orderBy('name')->get();

        return response()->json([
            'total' => $wards->count(),
            'wards' => $wards->map(fn ($w) => [
                'code'          => $w->code,
                'name'          => $w->name,
                'division_type' => $w->division_type,
                'codename'      => $w->codename,
                'province_code' => $w->province_code,
            ])->values(),
        ]);
    }

    // GET /api/v2/ward/{code}
    public function show(int $code): JsonResponse
    {
        $ward = Ward::where('code', $code)->first();

        if (! $ward) {
            return response()->json(['message' => 'Không tìm thấy phường/xã.'], 404);
        }

        return response()->json([
            'code'          => $ward->code,
            'name'          => $ward->name,
            'division_type' => $ward->division_type,
            'codename'      => $ward->codename,
            'province_code' => $ward->province_code,
        ]);
    }

    // GET /api/v1/wards/{code}
    public function showByCode(int $code): JsonResponse
    {
        $ward = Ward::where('code', $code)->first();

        if (! $ward) {
            return response()->json(['message' => 'Không tìm thấy phường/xã.'], 404);
        }

        $branches = ProvinceBranch::where('ward_code', $code)
            ->where('status', true)
            ->with('category')
            ->get()
            ->map(function ($branch) {
                $category   = $branch->category;
                $categoryId = $category->id;
                $childIds   = Category::where('parent_id', $categoryId)->pluck('id')->toArray();
                $allIds     = array_merge([$categoryId], $childIds);

                $rooms = Product::where('is_activated', true)
                    ->where('is_in_stock', true)
                    ->whereHas('categories', fn ($q) => $q->whereIn('category_id', $allIds))
                    ->with(['roomType', 'media', 'roomTimeSlots.timeSlot', 'roomTimeSlots.promotions'])
                    ->get()
                    ->map(fn ($room) => $this->mapRoom($room))
                    ->values();

                return [
                    'id'          => $category->id,
                    'name'        => $category->name,
                    'slug'        => $category->slug,
                    'description' => $category->description,
                    'image_url'   => $category->image
                        ? Storage::disk('public')->url($category->image)
                        : null,
                    'items'       => $rooms,
                ];
            })
            ->values();

        return response()->json([
            'ward' => [
                'code'     => $ward->code,
                'name'     => $ward->name,
                'branches' => $branches,
            ],
        ]);
    }
}

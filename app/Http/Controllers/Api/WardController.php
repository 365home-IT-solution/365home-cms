<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Ward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WardController extends Controller
{
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
}

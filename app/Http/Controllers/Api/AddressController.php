<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Province;
use App\Models\Ward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

// API địa chỉ công khai cho form nhập địa chỉ (đăng ký hợp tác...): ô tìm kiếm gợi ý, danh sách tỉnh/thành. Phường/xã theo tỉnh: GET /api/v2/ward?province_code=.
class AddressController extends Controller
{
    // GET /api/v2/address/suggest?q=Cần&limit=8 — gợi ý tỉnh/thành + phường/xã theo từ khoá (tìm không phân biệt dấu/hoa thường).
    public function suggest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q'     => ['required', 'string', 'min:1', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ], [], ['q' => 'từ khoá']);

        $q = trim($data['q']);
        $limit = (int) ($data['limit'] ?? 8);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

        // Tỉnh/thành trước (tối đa 3), phần còn lại là phường/xã; tên bắt đầu bằng từ khoá xếp trước.
        $provinces = Province::query()->whereNotNull('code')->where('name', 'like', $like)
            ->orderByRaw('name like ? desc', [str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%'])->orderBy('name')
            ->limit(min(3, $limit))->get(['code', 'name']);

        $wards = Ward::query()->with('province:code,name')->where('name', 'like', $like)
            ->orderByRaw('name like ? desc', [str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%'])->orderBy('name')
            ->limit($limit - $provinces->count())->get(['code', 'name', 'province_code']);

        $suggestions = $provinces->map(fn (Province $p) => [
            'type'          => 'province',
            'label'         => $p->name,
            'description'   => 'Việt Nam',
            'province_code' => $p->code,
            'province_name' => $p->name,
            'ward_code'     => null,
            'ward_name'     => null,
        ])->concat($wards->map(fn (Ward $w) => [
            'type'          => 'ward',
            'label'         => $w->name,
            'description'   => ($w->province?->name ? $w->province->name . ', ' : '') . 'Việt Nam',
            'province_code' => $w->province_code,
            'province_name' => $w->province?->name,
            'ward_code'     => $w->code,
            'ward_name'     => $w->name,
        ]))->values();

        return response()->json([
            'query'       => $q,
            'total'       => $suggestions->count(),
            'country'     => ['code' => 'VN', 'name' => 'Việt Nam'],
            'suggestions' => $suggestions,
        ]);
    }

    // GET /api/v2/address/provinces — toàn bộ tỉnh/thành phố (dùng cho ô chọn Tỉnh/Thành phố).
    public function provinces(): JsonResponse
    {
        // Bỏ các dòng dữ liệu cũ chưa có mã tỉnh (code null) — không dùng được để chọn địa chỉ.
        $provinces = Province::query()->whereNotNull('code')->orderBy('name')->get(['code', 'name', 'division_type', 'codename']);

        return response()->json([
            'total'     => $provinces->count(),
            'country'   => ['code' => 'VN', 'name' => 'Việt Nam'],
            'provinces' => $provinces->map(fn (Province $p) => [
                'code'          => $p->code,
                'name'          => $p->name,
                'division_type' => $p->division_type,
                'codename'      => $p->codename,
            ])->values(),
        ]);
    }
}

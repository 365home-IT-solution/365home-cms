<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Models\Province;
use Illuminate\Http\Request;

trait ResolvesProvince
{
    /**
     * Resolve province theo thứ tự ưu tiên:
     *   1. Query param ?province_id={id} — app gửi tỉnh user đang xem (override)
     *   2. Query param ?province={slug} (backward compat)
     *   3. Customer đăng nhập → customer.province_id (fallback nếu không có param)
     */
    protected function resolveProvince(Request $request): ?Province
    {
        if ($request->query('province_id')) {
            return Province::find((int) $request->query('province_id'));
        }

        if ($request->query('province')) {
            return Province::where('slug', $request->query('province'))->first();
        }

        $user = auth('sanctum')->user();
        if ($user?->province_id) {
            return Province::find($user->province_id);
        }

        return null;
    }
}

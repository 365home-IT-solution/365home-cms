<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Models\Province;
use Illuminate\Http\Request;

trait ResolvesProvince
{
    /**
     * Resolve province theo thứ tự ưu tiên:
     *   1. Customer đăng nhập → customer.province_id (auto từ DB)
     *   2. Query param ?province_id={id} (guest hoặc override — app cache local)
     *   3. Query param ?province={slug} (backward compat)
     */
    protected function resolveProvince(Request $request): ?Province
    {
        $user = auth('sanctum')->user();
        if ($user?->province_id) {
            return Province::find($user->province_id);
        }

        if ($request->query('province_id')) {
            return Province::find((int) $request->query('province_id'));
        }

        if ($request->query('province')) {
            return Province::where('slug', $request->query('province'))->first();
        }

        return null;
    }
}

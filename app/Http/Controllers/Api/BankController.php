<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Support\Banks;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

// GET /api/v2/banks — danh sách ngân hàng cho ô CHỌN ngân hàng (không cho nhập tay). Gửi lên bằng bank_code.
class BankController extends Controller
{
    public function index(): JsonResponse
    {
        $banks = collect(Banks::all())->map(fn (array $b, string $code) => ['code' => $code] + $b)->sortBy('short_name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return response()->json(['total' => $banks->count(), 'banks' => $banks]);
    }
}

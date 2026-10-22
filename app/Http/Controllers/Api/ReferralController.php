<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReferralService;
use App\Services\ScratchCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Giới thiệu bạn bè cho khách đã đăng nhập. Xem App\Services\ReferralService.
class ReferralController extends Controller
{
    public function __construct(private readonly ReferralService $service) {}

    /** GET /api/referrals/me — mã giới thiệu của tôi, danh sách người đã mời (che tên/SĐT), mã tôi đã nhập. */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->mePayload($request->user())]);
    }

    /**
     * POST /api/referrals/apply
     * Body: code, device_id (tuỳ chọn, để chặn nhiều tài khoản cùng thiết bị nhập cùng một mã)
     * 422 kèm message rõ ràng (app hiện nguyên văn) khi mã không hợp lệ.
     */
    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'      => 'required|string|max:20',
            'device_id' => 'nullable|string|max:100',
        ]);

        $result = $this->service->apply($request->user(), $data['code'], $data['device_id'] ?? null);

        return response()->json(['data' => [
            'applied_code' => $result['referral']->code,
            'coupon'       => ScratchCardService::couponPayload($result['coupon']),
        ]]);
    }
}

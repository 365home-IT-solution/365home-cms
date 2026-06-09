<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\FcmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DeviceTokenController extends Controller
{
    /**
     * Lưu hoặc cập nhật FCM device token của khách.
     * App gọi sau khi đăng nhập hoặc khi Firebase cấp token mới.
     *
     * POST /api/device-token
     * Body: { "token": "FCM_TOKEN_STRING" }
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        $token = $request->input('token');

        if (! config('app.fcm_bypass_enabled', false) && ! app(FcmService::class)->validateToken($token)) {
            return response()->json([
                'message' => 'Token không hợp lệ hoặc không được Firebase xác nhận.',
                'errors'  => ['token' => ['Token không hợp lệ.']],
            ], 422);
        }

        /** @var \App\Models\Customer $customer */
        $customer = $request->user();
        $customer->update(['token_device' => $token]);

        return response()->json(['message' => 'Token đã được lưu.']);
    }

    /**
     * Xóa device token khi khách đăng xuất hoặc tắt thông báo.
     *
     * DELETE /api/device-token
     */
    public function destroy(Request $request): JsonResponse
    {
        /** @var \App\Models\Customer $customer */
        $customer = $request->user();
        $customer->update(['token_device' => null]);

        return response()->json(['message' => 'Token đã được xóa.']);
    }
}

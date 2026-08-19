<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\FcmToken;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Đăng ký FCM/Expo push token cho APP ADMIN (React Native, xác thực Bearer qua auth:sanctum) — KHÁC
 * route web `POST /admin/api/fcm-token` (routes/web.php, session + CSRF, dành cho trình duyệt tự
 * đăng ký Firebase Web Push token trên Filament panel). App không có session/cookie Laravel nên
 * không gọi được route web đó (419 CSRF, hoặc 401 do session guard không đọc Bearer header) — đây là
 * route riêng cho đúng luồng Bearer token của app.
 */
class FcmTokenController extends Controller
{
    /**
     * POST /api/admin/fcm-token
     * Body: { "token": "ExponentPushToken[...]" | "<fcm-device-token>" }
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        $token = $request->input('token');

        if (! config('app.fcm_bypass_enabled', false) && ! app(FcmService::class)->validateToken($token)) {
            return response()->json([
                'message' => 'Token không hợp lệ hoặc không được xác nhận.',
                'errors'  => ['token' => ['Token không hợp lệ.']],
            ], 422);
        }

        /** @var User $user */
        $user = $request->user();

        FcmToken::upsertForUser((string) $user->id, $token);

        return response()->json(['ok' => true]);
    }
}

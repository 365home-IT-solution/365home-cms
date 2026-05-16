<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ZaloOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZaloOtpController extends Controller
{
    public function __construct(protected ZaloOtpService $otp) {}

    /**
     * Gửi OTP về Zalo của khách hàng.
     * Body: { phone: "0912345678" }
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'regex:/^(0|\+84)[0-9]{9}$/'],
        ]);

        if ($this->otp->hasReachedDailyLimit($request->phone)) {
            return response()->json(['message' => 'Bạn đã gửi quá nhiều OTP hôm nay. Vui lòng thử lại vào ngày mai.'], 429);
        }

        if ($this->otp->hasCooldown($request->phone)) {
            return response()->json(['message' => 'Vui lòng đợi 60 giây trước khi gửi lại OTP.'], 429);
        }

        $sent = $this->otp->send($request->phone);

        if (! $sent) {
            return response()->json(['message' => 'Không thể gửi OTP qua Zalo. Vui lòng thử lại.'], 500);
        }

        return response()->json(['message' => 'OTP đã được gửi đến Zalo của bạn.']);
    }

    /**
     * Xác nhận OTP và trả về Sanctum token.
     * Body: { phone: "0912345678", otp: "123456", fullname?: "..." }
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone'    => ['required', 'string', 'regex:/^(0|\+84)[0-9]{9}$/'],
            'otp'      => 'required|string|size:6',
            'fullname' => 'nullable|string|max:255',
        ]);

        if (! $this->otp->verify($request->phone, $request->otp)) {
            return response()->json(['message' => 'OTP không đúng hoặc đã hết hạn.'], 422);
        }

        $normalizedPhone = $this->otp->normalizePhone($request->phone);
        $existing        = User::where('phone', $normalizedPhone)->first();

        $user = User::updateOrCreate(
            ['phone' => $normalizedPhone],
            [
                'phone_verified_at' => now(),
                'fullname'          => $request->fullname ?? $existing?->fullname,
            ]
        );

        $user->tokens()->delete();
        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $this->userResource($user),
        ]);
    }

    /**
     * Đăng xuất — xóa Sanctum token hiện tại.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Đăng xuất thành công.']);
    }

    /**
     * Thông tin user đang đăng nhập.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userResource($request->user()));
    }

    private function userResource(User $user): array
    {
        return [
            'id'                => $user->id,
            'fullname'          => $user->fullname,
            'phone'             => $user->phone,
            'email'             => $user->email,
            'phone_verified_at' => $user->phone_verified_at?->toIso8601String(),
            'avatar'            => $user->getFilamentAvatarUrl(),
        ];
    }
}

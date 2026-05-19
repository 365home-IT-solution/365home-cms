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
            return response()->json([
                'message' => 'Không thể gửi OTP đến số này qua Zalo. Vui lòng kiểm tra số điện thoại đã đăng ký Zalo chưa, hoặc liên hệ hỗ trợ.',
            ], 422);
        }

        return response()->json(['message' => 'OTP đã được gửi đến Zalo của bạn. Vui lòng kiểm tra ứng dụng Zalo.']);
    }

    /**
     * Xác nhận OTP và trả về Sanctum token.
     * Đăng ký: truyền thêm fullname + date_of_birth
     * Đăng nhập: chỉ cần phone + otp
     * Body: { phone, otp, fullname?, date_of_birth? }
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone'         => ['required', 'string', 'regex:/^(0|\+84)[0-9]{9}$/'],
            'otp'           => 'required|string|size:6',
            'fullname'      => 'nullable|string|max:255',
            'date_of_birth' => 'nullable|date_format:d-m-Y|before:today',
        ]);

        if (! $this->otp->verify($request->phone, $request->otp)) {
            return response()->json(['message' => 'OTP không đúng hoặc đã hết hạn.'], 422);
        }

        $normalizedPhone = $this->otp->normalizePhone($request->phone);
        $existing        = User::where('phone', $normalizedPhone)->first();
        $isNewUser       = $existing === null;

        $parsedDob = $request->date_of_birth
            ? \Carbon\Carbon::createFromFormat('d-m-Y', $request->date_of_birth)->toDateString()
            : null;

        $user = User::updateOrCreate(
            ['phone' => $normalizedPhone],
            array_filter([
                'phone_verified_at' => now(),
                'fullname'          => $request->fullname ?? $existing?->fullname,
                'date_of_birth'     => $parsedDob         ?? $existing?->date_of_birth?->toDateString(),
            ], fn ($v) => $v !== null)
        );

        $user->tokens()->delete();
        $expiresAt = now()->addDays(30);
        $token     = $user->createToken('mobile', ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'token'       => $token,
            'expires_at'  => $expiresAt->toIso8601String(),
            'is_new_user' => $isNewUser,
            'user'        => $this->userResource($user),
        ]);
    }

    /**
     * Bước 2 flow đăng ký: xác minh OTP lần đầu, trả về phone_token.
     * Nếu SĐT đã tồn tại → báo redirect đăng nhập.
     * Body: { phone, otp }
     */
    public function preVerify(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'regex:/^(0|\+84)[0-9]{9}$/'],
            'otp'   => 'required|string|size:6',
        ]);

        if (! $this->otp->verify($request->phone, $request->otp)) {
            return response()->json(['message' => 'OTP không đúng hoặc đã hết hạn.'], 422);
        }

        $normalizedPhone = $this->otp->normalizePhone($request->phone);

        if (User::where('phone', $normalizedPhone)->exists()) {
            return response()->json([
                'message'  => 'Số điện thoại này đã có tài khoản. Vui lòng đăng nhập.',
                'redirect' => 'login',
            ], 409);
        }

        $phoneToken = $this->otp->storePhoneToken($normalizedPhone);

        return response()->json([
            'phone_token' => $phoneToken,
            'expires_in'  => 1800,
        ]);
    }

    /**
     * Bước 4 flow đăng ký: tạo tài khoản sau khi điền thông tin.
     * Body: { phone_token, fullname, date_of_birth }
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'phone_token'   => 'required|string|size:64',
            'fullname'      => 'required|string|max:255',
            'date_of_birth' => 'required|date_format:d-m-Y|before:today',
        ]);

        $normalizedPhone = $this->otp->getPhoneByToken($request->phone_token);

        if (! $normalizedPhone) {
            return response()->json([
                'message' => 'Phiên đăng ký đã hết hạn (30 phút). Vui lòng thực hiện lại từ đầu.',
            ], 422);
        }

        if (User::where('phone', $normalizedPhone)->exists()) {
            return response()->json([
                'message'  => 'Số điện thoại này đã có tài khoản. Vui lòng đăng nhập.',
                'redirect' => 'login',
            ], 409);
        }

        $this->otp->consumePhoneToken($request->phone_token);

        $user = User::create([
            'phone'             => $normalizedPhone,
            'fullname'          => $request->fullname,
            'date_of_birth'     => \Carbon\Carbon::createFromFormat('d-m-Y', $request->date_of_birth)->toDateString(),
            'phone_verified_at' => now(),
        ]);

        $expiresAt = now()->addDays(30);
        $token     = $user->createToken('mobile', ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'token'       => $token,
            'expires_at'  => $expiresAt->toIso8601String(),
            'is_new_user' => true,
            'user'        => $this->userResource($user),
        ], 201);
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
            'date_of_birth'     => $user->date_of_birth?->toDateString(),
            'phone'             => $user->phone,
            'email'             => $user->email,
            'phone_verified_at' => $user->phone_verified_at?->toIso8601String(),
            'avatar'            => $user->getFilamentAvatarUrl(),
        ];
    }
}

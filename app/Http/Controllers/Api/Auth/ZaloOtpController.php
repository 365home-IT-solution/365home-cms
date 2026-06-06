<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\ZaloOtpService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Payment\App\Services\CccdScannerService;

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
                'message' => 'Số điện thoại chưa đăng ký Zalo hoặc không thể gửi tin nhắn. Vui lòng kiểm tra lại.',
            ], 422);
        }

        return response()->json(['message' => 'OTP đã được gửi đến Zalo của bạn. Vui lòng kiểm tra ứng dụng Zalo.']);
    }

    /**
     * Xác nhận OTP.
     * - SĐT đã có tài khoản → đăng nhập, trả Sanctum token.
     * - SĐT chưa có tài khoản → trả phone_token để tiếp tục đăng ký.
     * Body: { phone, otp }
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'regex:/^(0|\+84)[0-9]{9}$/'],
            'otp'   => 'required|string|size:6',
        ]);

        if (! $this->otp->verify($request->phone, $request->otp)) {
            return response()->json(['message' => 'OTP không đúng hoặc đã hết hạn.'], 422);
        }

        $normalizedPhone = $this->otp->normalizePhone($request->phone);
        $customer        = Customer::where('phone', $normalizedPhone)->first();

        if ($customer) {
            $customer->phone_verified_at = now();
            $customer->save();

            $customer->tokens()->delete();
            $expiresAt = now()->addDays(30);
            $token     = $customer->createToken('mobile', ['*'], $expiresAt)->plainTextToken;

            return response()->json([
                'is_new_user' => false,
                'token'       => $token,
                'expires_at'  => $expiresAt->toIso8601String(),
                'user'        => $this->customerResource($customer),
            ]);
        }

        $phoneToken = $this->otp->storePhoneToken($normalizedPhone);

        return response()->json([
            'is_new_user' => true,
            'phone_token' => $phoneToken,
            'expires_in'  => 1800,
        ]);
    }

    /**
     * Tạo tài khoản khách hàng sau khi xác thực OTP.
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

        if (Customer::where('phone', $normalizedPhone)->exists()) {
            return response()->json([
                'message'  => 'Số điện thoại này đã có tài khoản. Vui lòng đăng nhập.',
                'redirect' => 'login',
            ], 409);
        }

        $this->otp->consumePhoneToken($request->phone_token);

        $customer = Customer::create([
            'phone'             => $normalizedPhone,
            'fullname'          => $request->fullname,
            'date_of_birth'     => Carbon::createFromFormat('d-m-Y', $request->date_of_birth)->toDateString(),
            'phone_verified_at' => now(),
        ]);

        $expiresAt = now()->addDays(30);
        $token     = $customer->createToken('mobile', ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'token'       => $token,
            'expires_at'  => $expiresAt->toIso8601String(),
            'is_new_user' => true,
            'user'        => $this->customerResource($customer),
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
     * Thông tin khách hàng đang đăng nhập.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json($this->customerResource($request->user()));
    }

    /**
     * Cập nhật thông tin khách hàng (yêu cầu token).
     * Body (multipart/form-data): fullname?, date_of_birth?, cccd_front?, cccd_back?
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'fullname'      => 'sometimes|string|max:255',
            'date_of_birth' => 'sometimes|date_format:d-m-Y|before:today',
            'avatar'        => 'sometimes|file|mimes:jpg,jpeg,png,webp|max:5120',
            'cccd_front'    => 'sometimes|file|mimes:jpg,jpeg,png,webp|max:5120',
            'cccd_back'     => 'sometimes|file|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $customer = $request->user();
        $data     = [];

        if ($request->filled('fullname')) {
            $data['fullname'] = $request->fullname;
        }

        if ($request->filled('date_of_birth')) {
            $data['date_of_birth'] = Carbon::createFromFormat('d-m-Y', $request->date_of_birth)->toDateString();
        }

        if ($request->hasFile('avatar')) {
            if ($customer->avatar) {
                Storage::disk('public')->delete($customer->avatar);
            }
            $data['avatar'] = $request->file('avatar')->store('avatars', 'public');
        }

        // Lưu path file cũ để xoá sau khi xác nhận QR hợp lệ
        $oldCccdFront = $customer->cccd_front;
        $oldCccdBack  = $customer->cccd_back;

        if ($request->hasFile('cccd_front')) {
            $data['cccd_front'] = $request->file('cccd_front')->store('cccd', 'public');
        }

        if ($request->hasFile('cccd_back')) {
            $data['cccd_back'] = $request->file('cccd_back')->store('cccd', 'public');
        }

        // Nếu có upload CCCD thì bắt buộc quét QR xác thực
        if (isset($data['cccd_front']) || isset($data['cccd_back'])) {
            $tempCustomer = new Customer([
                'cccd_front' => $data['cccd_front'] ?? $customer->cccd_front,
                'cccd_back'  => $data['cccd_back']  ?? $customer->cccd_back,
            ]);

            $cccdData = app(CccdScannerService::class)->scanCustomer($tempCustomer);

            if (! $cccdData) {
                // QR không đọc được — xoá file mới, giữ nguyên file cũ
                if (isset($data['cccd_front'])) {
                    Storage::disk('public')->delete($data['cccd_front']);
                }
                if (isset($data['cccd_back'])) {
                    Storage::disk('public')->delete($data['cccd_back']);
                }

                return response()->json([
                    'message' => 'Không đọc được QR trên ảnh CCCD. Vui lòng upload ảnh gốc rõ nét, không chụp lại màn hình.',
                ], 422);
            }

            // QR hợp lệ — xoá file cũ và lưu dữ liệu
            if (isset($data['cccd_front']) && $oldCccdFront) {
                Storage::disk('public')->delete($oldCccdFront);
            }
            if (isset($data['cccd_back']) && $oldCccdBack) {
                Storage::disk('public')->delete($oldCccdBack);
            }

            $data['cccd_data'] = $cccdData;
        }

        if (! empty($data)) {
            $customer->update($data);
        }

        return response()->json($this->customerResource(
            Customer::find($customer->id) ?? $customer
        ));
    }

    private function customerResource(Customer $customer): array
    {
        return [
            'id'                => $customer->id,
            'fullname'          => $customer->fullname,
            'date_of_birth'     => $customer->date_of_birth?->toDateString(),
            'phone'             => $customer->phone,
            'phone_verified_at' => $customer->phone_verified_at?->toIso8601String(),
            'avatar'            => $customer->avatar
                ? Storage::disk('public')->url($customer->avatar)
                : null,
            'cccd_front'        => $customer->cccd_front
                ? Storage::disk('public')->url($customer->cccd_front)
                : null,
            'cccd_back'         => $customer->cccd_back
                ? Storage::disk('public')->url($customer->cccd_back)
                : null,
            'cccd_data'         => $customer->cccd_data,
        ];
    }
}

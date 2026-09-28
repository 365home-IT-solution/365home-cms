<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerCompanion;
use App\Models\GuestCustomer;
use App\Models\MembershipTier;
use App\Services\CccdIntakeService;
use App\Services\ZaloOtpService;
use App\Support\CccdIdentity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Promotion\App\Models\Coupon;

class ZaloOtpController extends Controller
{
    public function __construct(
        protected ZaloOtpService $otp,
    ) {}

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
        $customer        = Customer::withTrashed()->where('phone', $normalizedPhone)->first();

        if ($customer) {
            // Tài khoản bị xoá vĩnh viễn hoặc soft-delete → không cho đăng nhập/đăng ký lại
            if ($customer->trashed()) {
                return response()->json([
                    'message' => 'Tài khoản này đã bị xoá. Vui lòng liên hệ hỗ trợ.',
                ], 403);
            }

            if ($customer->status === Customer::STATUS_INACTIVE) {
                return response()->json([
                    'message' => 'Tài khoản của bạn đã bị vô hiệu hóa. Vui lòng liên hệ hỗ trợ để được kích hoạt lại.',
                ], 403);
            }

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
     * Đăng nhập bằng số điện thoại + mật khẩu.
     * Body: { phone, password }
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'phone'    => ['required', 'string', 'regex:/^(0|\+84)[0-9]{9}$/'],
            'password' => 'required|string',
        ]);

        $normalizedPhone = $this->otp->normalizePhone($request->phone);
        $customer        = Customer::where('phone', $normalizedPhone)->first();

        if (! $customer || ! $customer->password) {
            return response()->json([
                'message' => 'Số điện thoại hoặc mật khẩu không đúng.',
            ], 401);
        }

        if ($customer->status === Customer::STATUS_INACTIVE) {
            return response()->json([
                'message' => 'Tài khoản của bạn đã bị vô hiệu hóa. Vui lòng liên hệ hỗ trợ để được kích hoạt lại.',
            ], 403);
        }

        if (! password_verify($request->password, $customer->password)) {
            return response()->json([
                'message' => 'Số điện thoại hoặc mật khẩu không đúng.',
            ], 401);
        }

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

    /**
     * Tạo tài khoản khách hàng sau khi xác thực OTP.
     * Body: { phone_token, fullname, date_of_birth, password, password_confirmation }
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'phone_token'          => 'required|string|size:64',
            'fullname'             => 'required|string|max:255',
            'date_of_birth'        => 'required|date_format:d-m-Y|before:today',
            'password'             => 'sometimes|nullable|string|min:8|confirmed',
            'password_confirmation'=> 'sometimes|nullable|string',
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

        $now      = now();
        $hasPassword = filled($request->password);
        $customer = Customer::create([
            'phone'               => $normalizedPhone,
            'fullname'            => $request->fullname,
            'date_of_birth'       => Carbon::createFromFormat('d-m-Y', $request->date_of_birth)->toDateString(),
            'phone_verified_at'   => $now,
            'status'              => Customer::STATUS_ACTIVE,
            'password'            => $hasPassword ? $request->password : null,
            'password_updated_at' => $hasPassword ? $now : null,
        ]);

        // Liên kết khách vãng lai bằng SĐT
        GuestCustomer::where('phone', $normalizedPhone)
            ->whereNull('customer_id')
            ->update(['customer_id' => $customer->id]);

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
        // Route này KHÔNG có middleware customer.active (cố ý — khách bị vô hiệu hóa vẫn phải xem
        // được chính hồ sơ mình để biết trạng thái), nên tự kiểm tra type ở đây thay vì middleware —
        // token của App\Models\User (admin) lỡ gọi nhầm route customer sẽ bị chặn rõ ràng bằng 401
        // thay vì crash TypeError ở customerResource() (đòi Customer, nhận User).
        $user = $request->user();

        if (! $user instanceof Customer) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json($this->customerResource($user));
    }

    /**
     * Đổi mật khẩu.
     * Body: { current_password, password, password_confirmation }
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password'      => 'required|string',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        $customer = $request->user();

        if (! $customer->password || ! password_verify($request->current_password, $customer->password)) {
            return response()->json([
                'message' => 'Mật khẩu hiện tại không đúng.',
                'errors'  => ['current_password' => ['Mật khẩu hiện tại không đúng.']],
            ], 422);
        }

        if ($request->current_password === $request->password) {
            return response()->json([
                'message' => 'Mật khẩu mới phải khác mật khẩu hiện tại.',
                'errors'  => ['password' => ['Mật khẩu mới phải khác mật khẩu hiện tại.']],
            ], 422);
        }

        $customer->update([
            'password'            => $request->password,
            'password_updated_at' => now(),
        ]);

        return response()->json(['message' => 'Đổi mật khẩu thành công.']);
    }

    /**
     * Vô hiệu hóa tài khoản — do khách tự yêu cầu.
     * Xoá toàn bộ token, đặt status = inactive.
     */
    public function deactivate(Request $request): JsonResponse
    {
        $customer = $request->user();

        $customer->tokens()->delete();
        $customer->update(['status' => Customer::STATUS_INACTIVE]);

        return response()->json(['message' => 'Tài khoản đã được vô hiệu hóa.']);
    }

    /**
     * Xoá tài khoản — do khách tự yêu cầu.
     * Xoá toàn bộ token Sanctum rồi soft-delete customer.
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $customer = $request->user();

        $customer->tokens()->delete();
        $customer->delete();

        return response()->json(['message' => 'Tài khoản đã được xoá thành công.']);
    }

    /**
     * Cập nhật thông tin khách hàng (yêu cầu token).
     * Body (multipart/form-data): fullname?, date_of_birth?, cccd_qr_image?,
     * companions[i][qr_image]?, companions[i][full_name]?  (app cũ: cccd_front/cccd_back,
     * companions[i][cccd_front|cccd_back])
     * (companions = CCCD người đi cùng lưu vào hồ sơ, chọn lại bằng companion_id khi đặt phòng
     * qua đêm — xem customer_companions). Người đi cùng trùng số CCCD với người đã có → cập nhật
     * người đó (không tạo bản ghi trùng); cùng số nhưng khác họ tên/ngày sinh → giữ dữ liệu cũ,
     * báo trong companions_sync.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'fullname'                => 'sometimes|string|max:255',
            'date_of_birth'           => 'sometimes|date_format:d-m-Y|before:today',
            'cccd_qr_image'           => 'sometimes|file|mimes:jpg,jpeg,png,webp|max:5120',
            'cccd_front'              => 'sometimes|file|mimes:jpg,jpeg,png,webp|max:5120',
            'cccd_back'               => 'sometimes|file|mimes:jpg,jpeg,png,webp|max:5120',
            'companions'              => 'sometimes|array',
            'companions.*.qr_image'   => 'sometimes|file|mimes:jpg,jpeg,png,webp|max:5120',
            'companions.*.cccd_front' => 'sometimes|file|mimes:jpg,jpeg,png,webp|max:5120',
            'companions.*.cccd_back'  => 'sometimes|file|mimes:jpg,jpeg,png,webp|max:5120',
            'companions.*.full_name'  => 'nullable|string|max:255',
        ]);

        $customer = $request->user();
        $data     = [];

        if ($request->filled('fullname')) {
            $data['fullname'] = $request->fullname;
        }

        if ($request->filled('date_of_birth')) {
            $data['date_of_birth'] = Carbon::createFromFormat('d-m-Y', $request->date_of_birth)->toDateString();
        }

        // ── CCCD chính chủ + người đi cùng ────────────────────────────────────
        // Đọc/kiểm tra HẾT trên file tạm trước (QR, cấu trúc số CCCD, trùng người), đạt mới lưu —
        // lỗi ở bất kỳ ai thì không thay đổi gì. KHÔNG xoá ảnh cũ: đơn đặt trước đây trỏ thẳng vào
        // file ảnh của hồ sơ (chưa copy), xoá sẽ làm đơn cũ mất ảnh.
        $intake   = app(CccdIntakeService::class);
        $actor    = 'customer:' . $customer->id;
        $self     = $intake->readFromRequest($request, 'cccd_qr_image', ['cccd_front', 'cccd_back'], 'cccd_qr_image', false, $actor);
        $newPeople = [];

        foreach (array_keys((array) $request->file('companions', [])) as $index) {
            $read = $intake->readFromRequest($request, "companions.{$index}.qr_image", ["companions.{$index}.cccd_front", "companions.{$index}.cccd_back"], "companions.{$index}.qr_image", true, $actor);
            $newPeople[$index] = $read + ['field' => "companions.{$index}.qr_image", 'label' => 'Người đi cùng thứ ' . ($index + 1)];
        }

        // Trùng người giữa CCCD chính chủ (mới gửi hoặc đang có) và các người đi cùng gửi lên cùng
        // lúc — tuổi không xét ở hồ sơ, xét khi đặt phòng.
        $selfData = $self['data'] ?? (is_array($customer->cccd_data) ? $customer->cccd_data : null);
        $intake->assertPeople(array_merge(
            $selfData ? [['field' => 'cccd_qr_image', 'label' => 'CCCD của bạn', 'is_booker' => true, 'data' => $selfData, 'skip_age' => true]] : [],
            array_values(array_map(fn ($p) => ['field' => $p['field'], 'label' => $p['label'], 'is_booker' => false, 'data' => $p['data'], 'skip_age' => true], $newPeople)),
        ), null);

        if ($self) {
            $data['cccd_qr_image'] = $intake->storeQrImage($self['file']);
            $data['cccd_data']     = $self['data'];
        }

        if (! empty($data)) {
            $customer->update($data);
        }

        $companionsSync = [];
        foreach ($newPeople as $index => $person) {
            $status = $intake->rememberCompanion($customer, $person['data'], $intake->storeQrImage($person['file']), copyImage: false);

            if (in_array($status, ['created', 'updated'], true) && $request->filled("companions.{$index}.full_name")) {
                $customer->companions()->get()
                    ->first(fn (CustomerCompanion $c) => ($c->cccd_data['cccd'] ?? null) === $person['data']['cccd'])
                    ?->update(['full_name' => $request->input("companions.{$index}.full_name")]);
            }

            $companionsSync[] = ['index' => $index, 'status' => $status];
        }

        return response()->json($this->customerResource(
            Customer::find($customer->id) ?? $customer
        ) + ['companions_sync' => $companionsSync]);
    }

    /**
     * Xoá 1 CCCD        return response()->json($this->customerResource(
            Customer::find($customer->id) ?? $customer
        ));
    }

    /**
     * Xoá 1 CCCD người đi cùng khỏi hồ sơ (yêu cầu token).
     * DELETE /api/auth/companions/{id}
     */
    public function deleteCompanion(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();

        $companion = $customer->companions()->find($id);

        if (! $companion) {
            return response()->json(['message' => 'Không tìm thấy người đi cùng.'], 404);
        }

        // Không xoá file ảnh: đơn đặt trước đây có thể còn trỏ thẳng vào ảnh của người đi cùng này.
        $companion->delete();

        return response()->json($this->customerResource(
            Customer::find($customer->id) ?? $customer
        ));
    }

    private function customerResource(Customer $customer): array
    {
        $customer->loadMissing(['membershipTier', 'companions']);

        $tier     = $customer->membershipTier;
        $spending = (float) $customer->total_spending;

        $nextTier = MembershipTier::where('is_active', true)
            ->where('min_spending', '>', $spending)
            ->orderBy('min_spending')
            ->first();

        $coupons = Coupon::where(function ($q) use ($customer) {
                $q->where('customer_id', $customer->id)
                  ->orWhereHas('customers', fn ($q2) => $q2->whereKey($customer->id));
            })
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', now()))
            ->where(fn ($q) => $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'))
            ->orderByDesc('created_at')
            ->get(['id', 'code', 'name', 'type', 'value', 'max_discount', 'min_order_value', 'end_at', 'is_exclusive']);

        return [
            'id'                => $customer->id,
            'fullname'          => $customer->fullname,
            'date_of_birth'     => $customer->date_of_birth?->toDateString(),
            'phone'             => $customer->phone,
            'status'            => $customer->status,
            'phone_verified_at' => $customer->phone_verified_at?->toIso8601String(),
            ...CccdIntakeService::imageUrls($customer),
            'cccd_data'         => $customer->cccd_data,
            // Hồ sơ có dùng được để đặt phòng không (cấu trúc số CCCD hợp lệ) — false thì app yêu
            // cầu khách tải lại ảnh CCCD mặt có mã QR.
            'cccd_valid'        => CccdIdentity::validate($customer->cccd_data, requireQr: false) === null,
            'companions'        => $customer->companions->map(fn (CustomerCompanion $c) => [
                'id'          => $c->id,
                'full_name'   => $c->full_name,
                ...CccdIntakeService::imageUrls($c),
                'cccd_data'   => $c->cccd_data,
                'cccd_valid'  => CccdIdentity::validate($c->cccd_data, requireQr: false) === null,
            ]),
            'membership'        => [
                'tier'           => $tier ? [
                    'id'                  => $tier->id,
                    'name'                => $tier->name,
                    'slug'                => $tier->slug,
                    'welcome_coupon_value'=> (float) $tier->welcome_coupon_value,
                ] : null,
                'total_spending' => $spending,
                'next_tier'      => $nextTier ? [
                    'id'           => $nextTier->id,
                    'name'         => $nextTier->name,
                    'min_spending' => (float) $nextTier->min_spending,
                    'remaining'    => max(0, (float) $nextTier->min_spending - $spending),
                ] : null,
                'coupons'        => $coupons->map(fn (Coupon $c) => [
                    'code'            => $c->code,
                    'name'            => $c->name,
                    'type'            => $c->type,
                    'value'           => (float) $c->value,
                    'max_discount'    => $c->max_discount ? (float) $c->max_discount : null,
                    'min_order_value' => $c->min_order_value ? (float) $c->min_order_value : null,
                    'expires_at'      => $c->end_at?->toDateString(),
                    // FE dùng field này làm điều kiện: nếu true, mã không được áp chung với bất
                    // kỳ mã nào khác (backend enforce ở BookingController::guardExclusiveCoupons()).
                    'is_exclusive'    => (bool) $c->is_exclusive,
                ]),
            ],
        ];
    }

}

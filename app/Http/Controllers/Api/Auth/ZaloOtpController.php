<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerCompanion;
use App\Models\GuestCustomer;
use App\Models\MembershipTier;
use App\Exceptions\CccdIntakeException;
use App\Models\CustomerCccdVerification;
use App\Services\CccdIntakeService;
use App\Support\CccdIdentity;
use App\Services\ZaloOtpService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Payment\App\Services\CccdScannerService;
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

        // Khách thuê MiniHouse dùng CHUNG tài khoản với khách đặt phòng: OTP đã chứng minh quyền sở hữu SĐT nên tự tạo tài khoản khách từ hồ sơ khách thuê.
        $fromTenant = false;
        if (! $customer && ($tenant = $this->findTenantByPhone($normalizedPhone))) {
            $customer   = $this->customerFromTenant($tenant, $normalizedPhone);
            $fromTenant = true;
        }

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
                'is_tenant'   => $fromTenant || (bool) $this->findTenantByPhone($normalizedPhone),
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

    /** Khách thuê MiniHouse theo SĐT (so cả dạng 0xxx / +84xxx / 84xxx vì hồ sơ khách thuê nhập tay). */
    private function findTenantByPhone(string $normalizedPhone): ?\Modules\Minihouse\App\Models\Tenant
    {
        // Customer lưu SĐT dạng 84xxxxxxxxx (ZaloOtpService::normalizePhone); hồ sơ khách thuê nhập tay có thể là 0xxxxxxxxx / +84xxxxxxxxx.
        $digits   = preg_replace('/\D/', '', $normalizedPhone);
        $national = str_starts_with($digits, '84') ? substr($digits, 2) : ltrim($digits, '0');
        $variants = array_unique([$normalizedPhone, $digits, '0' . $national, '+84' . $national, '84' . $national]);

        return \Modules\Minihouse\App\Models\Tenant::withoutGlobalScopes()->whereIn('phone', $variants)->latest('id')->first();
    }

    /** Tạo tài khoản khách (dùng chung) từ hồ sơ khách thuê — cùng SĐT, giữ tên/ngày sinh; $passwordHash đã băm (nếu đăng nhập bằng mật khẩu khách thuê). */
    private function customerFromTenant(\Modules\Minihouse\App\Models\Tenant $tenant, string $normalizedPhone, ?string $passwordHash = null): Customer
    {
        $customer = new Customer();
        $customer->forceFill([
            'fullname'          => $tenant->fullname ?: 'Khách thuê',
            'date_of_birth'     => $tenant->date_of_birth,
            'phone'             => $normalizedPhone,
            'phone_verified_at' => now(),
            'status'            => Customer::STATUS_ACTIVE,
        ]);
        // Gán mật khẩu đã băm trực tiếp (tránh băm 2 lần nếu Customer có cast hashed).
        if ($passwordHash) {
            $customer->setRawAttributes(array_merge($customer->getAttributes(), ['password' => $passwordHash]));
        }
        $customer->save();

        return $customer;
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

        // Khách thuê MiniHouse đăng nhập bằng CHÍNH mật khẩu cổng khách thuê của mình: khớp mật khẩu khách thuê thì dùng/tạo tài khoản khách chung.
        $tenant = $this->findTenantByPhone($normalizedPhone);
        $viaTenantPassword = $tenant && $tenant->password && password_verify($request->password, $tenant->password)
            && (! $customer || ! $customer->password || ! password_verify($request->password, $customer->password));

        if ($viaTenantPassword) {
            if ($customer && $customer->trashed()) {
                return response()->json(['message' => 'Tài khoản này đã bị xoá. Vui lòng liên hệ hỗ trợ.'], 403);
            }

            $customer ??= $this->customerFromTenant($tenant, $normalizedPhone, $tenant->password);
        }

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

        if (! $viaTenantPassword && ! password_verify($request->password, $customer->password)) {
            return response()->json([
                'message' => 'Số điện thoại hoặc mật khẩu không đúng.',
            ], 401);
        }

        $customer->tokens()->delete();
        $expiresAt = now()->addDays(30);
        $token     = $customer->createToken('mobile', ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'is_new_user' => false,
            'is_tenant'   => (bool) $tenant,
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
     * Body (multipart/form-data): fullname?, date_of_birth?, cccd_front?, cccd_back?,
     * companions[i][cccd_front]?, companions[i][cccd_back]?, companions[i][full_name]?
     * (companions = CCCD người đi cùng lưu vào hồ sơ, tái sử dụng cho các lần đặt phòng
     * qua đêm sau này — xem customer_companions).
     *
     * Tuỳ chọn 1 ảnh mặt có mã QR (song song, không thay luồng 2 mặt ở trên):
     *  - cccd_qr_image             thay cho cccd_front + cccd_back của chính chủ
     *  - companions[i][qr_image]   thay cho companions[i][cccd_front] + [cccd_back]
     * Luồng này chỉ nhận dữ liệu từ QR, lỗi trả 422 {message, code, field}; response có thêm
     * companions_sync: [{index, status: created|updated|conflict|skipped}].
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'fullname'                => 'sometimes|string|max:255',
            'date_of_birth'           => 'sometimes|date_format:d-m-Y|before:today',
            'cccd_front'              => 'sometimes|file|mimes:jpg,jpeg,png,webp|max:5120',
            'cccd_back'               => 'sometimes|file|mimes:jpg,jpeg,png,webp|max:5120',
            'cccd_qr_image'           => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'companions'              => 'sometimes|array',
            // 2 mặt vẫn bắt buộc như cũ, TRỪ KHI người đi cùng đó gửi qr_image.
            'companions.*.cccd_front' => 'required_without:companions.*.qr_image|file|mimes:jpg,jpeg,png,webp|max:5120',
            'companions.*.cccd_back'  => 'required_without:companions.*.qr_image|file|mimes:jpg,jpeg,png,webp|max:5120',
            'companions.*.qr_image'   => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'companions.*.full_name'  => 'nullable|string|max:255',
        ], [
            // Giữ nguyên câu báo lỗi "bắt buộc" như trước khi có qr_image.
            'companions.*.cccd_front.required_without' => __('validation.required'),
            'companions.*.cccd_back.required_without'  => __('validation.required'),
        ]);

        $customer = $request->user();
        $data     = [];

        if ($request->filled('fullname')) {
            $data['fullname'] = $request->fullname;
        }

        if ($request->filled('date_of_birth')) {
            $data['date_of_birth'] = Carbon::createFromFormat('d-m-Y', $request->date_of_birth)->toDateString();
        }

        // Luồng 1 ảnh mặt có mã QR (tuỳ chọn) — quét trên file tạm TRƯỚC khi lưu bất cứ gì; lỗi ném
        // CccdIntakeException → 422 {message, code, field}. Có cccd_qr_image thì bỏ qua cccd_front/back.
        $intake = app(CccdIntakeService::class);
        $qrFile = $request->hasFile('cccd_qr_image') ? $request->file('cccd_qr_image') : null;
        $qrData = null;

        if ($qrFile) {
            $qrData = $intake->readQrForSave($qrFile, 'cccd_qr_image');

            $duplicate = $customer->companions()->get()
                ->first(fn (CustomerCompanion $c) => is_array($c->cccd_data) && $c->cccd_data && CccdIdentity::samePerson($qrData, $c->cccd_data));
            if ($duplicate) {
                throw new CccdIntakeException(
                    'CCCD này đang được lưu cho người đi cùng (' . ($duplicate->full_name ?: 'không tên') . ') trong hồ sơ của bạn.',
                    CccdIntakeException::DUPLICATE,
                    'cccd_qr_image',
                    422,
                    ['companion_id' => $duplicate->id],
                );
            }
        }

        // Lưu path file cũ để xoá sau khi xác nhận QR hợp lệ
        $oldCccdFront = $customer->cccd_front;
        $oldCccdBack  = $customer->cccd_back;

        if (! $qrFile && $request->hasFile('cccd_front')) {
            $data['cccd_front'] = $request->file('cccd_front')->store('cccd', 'public');
        }

        if (! $qrFile && $request->hasFile('cccd_back')) {
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
                    'message' => 'Không đọc được QR trên ảnh CCCD. ' . \Modules\Payment\App\Services\CccdScannerService::failureHint(),
                    'reason'  => \Modules\Payment\App\Services\CccdScannerService::lastFailure(),
                ], 422);
            }

            $data['cccd_data'] = $cccdData;
        }

        if (! empty($data)) {
            $customer->update($data);
        }

        // Luồng QR: hồ sơ nhận ảnh QR + dữ liệu mới, ghi thêm 1 dòng lịch sử xác thực (giống trang tài
        // khoản trên web). Ảnh cccd_front/back cũ của hồ sơ giữ nguyên.
        if ($qrFile) {
            $intake->recordCustomerVerification($customer, $qrData, $qrFile, CustomerCccdVerification::SOURCE_APP);
            $customer->refresh();
        }

        // Ảnh cũ bị thay: chỉ xoá file không còn bản ghi nào dùng — đơn đặt qua app trước đây trỏ
        // thẳng vào file ảnh của hồ sơ, xoá bừa sẽ làm đơn cũ mất ảnh CCCD.
        app(CccdIntakeService::class)->deleteUnreferencedImages(array_filter([
            isset($data['cccd_front']) ? $oldCccdFront : null,
            isset($data['cccd_back']) ? $oldCccdBack : null,
        ]));

        // Thêm CCCD người đi cùng vào hồ sơ.
        $companionsSync = [];

        if ($request->has('companions')) {
            $uploadedPaths = [];
            $scanner       = app(CccdScannerService::class);

            try {
                DB::transaction(function () use ($request, $customer, $scanner, $intake, &$uploadedPaths, &$companionsSync) {
                    foreach ($request->file('companions') as $index => $files) {
                        // Người đi cùng gửi 1 ảnh mặt có mã QR thay cho 2 mặt — lưu có chống trùng theo
                        // số CCCD (CccdIntakeService::rememberCompanion), kết quả trả ở companions_sync.
                        if (! empty($files['qr_image'])) {
                            $field     = "companions.{$index}.qr_image";
                            $qrData    = $intake->readQrForSave($files['qr_image'], $field);
                            $qrPath    = $intake->storeQrImage($files['qr_image']);
                            $uploadedPaths[] = $qrPath;

                            $companionsSync[] = [
                                'index'  => $index,
                                'status' => $intake->rememberCompanion($customer, $qrData, $qrPath, copyImage: false),
                            ];
                            continue;
                        }

                        $frontPath = $files['cccd_front']->store('cccd', 'public');
                        $backPath  = $files['cccd_back']->store('cccd', 'public');
                        $uploadedPaths[] = $frontPath;
                        $uploadedPaths[] = $backPath;

                        $cccdData = $scanner->scanPaths($frontPath, $backPath);

                        if (! $cccdData) {
                            throw new \RuntimeException(
                                'Không đọc được QR trên CCCD người đi cùng thứ ' . ($index + 1) . '. ' . \Modules\Payment\App\Services\CccdScannerService::failureHint()
                            );
                        }

                        CustomerCompanion::create([
                            'customer_id' => $customer->id,
                            'full_name'   => $request->input("companions.$index.full_name") ?: ($cccdData['full_name'] ?? null),
                            'cccd_front'  => $frontPath,
                            'cccd_back'   => $backPath,
                            'cccd_data'   => $cccdData,
                        ]);
                    }
                });
            } catch (CccdIntakeException $e) {
                foreach ($uploadedPaths as $path) {
                    Storage::disk('public')->delete($path);
                }

                return $e->render();
            } catch (\RuntimeException $e) {
                foreach ($uploadedPaths as $path) {
                    Storage::disk('public')->delete($path);
                }

                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        $resource = $this->customerResource(Customer::find($customer->id) ?? $customer);

        return response()->json($companionsSync ? $resource + ['companions_sync' => $companionsSync] : $resource);
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

        $paths = [$companion->cccd_front, $companion->cccd_back, $companion->cccd_qr_image];

        $companion->delete();

        // Chỉ xoá file không còn bản ghi nào dùng (đơn cũ có thể trỏ thẳng vào ảnh người đi cùng).
        app(CccdIntakeService::class)->deleteUnreferencedImages($paths);

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
            'cccd_front'        => $customer->cccd_front
                ? Storage::disk('public')->url($customer->cccd_front)
                : null,
            'cccd_back'         => $customer->cccd_back
                ? Storage::disk('public')->url($customer->cccd_back)
                : null,
            'cccd_qr_image'     => $customer->cccd_qr_image
                ? Storage::disk('public')->url($customer->cccd_qr_image)
                : null,
            'cccd_data'         => $customer->cccd_data,
            'companions'        => $customer->companions->map(fn (CustomerCompanion $c) => [
                'id'          => $c->id,
                'full_name'   => $c->full_name,
                'cccd_front'  => $c->cccd_front ? Storage::disk('public')->url($c->cccd_front) : null,
                'cccd_back'   => $c->cccd_back  ? Storage::disk('public')->url($c->cccd_back)  : null,
                'cccd_qr_image' => $c->cccd_qr_image ? Storage::disk('public')->url($c->cccd_qr_image) : null,
                'cccd_data'   => $c->cccd_data,
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

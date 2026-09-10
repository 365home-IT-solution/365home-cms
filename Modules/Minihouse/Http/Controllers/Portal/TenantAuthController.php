<?php

namespace Modules\Minihouse\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Services\TenantOtpService;
use Modules\Minihouse\App\Services\TenantPortalService;

// Đăng nhập Portal khách thuê — cho chọn 1 trong 2 cách MỖI LẦN đăng nhập (xem
// portal/auth/login.blade.php, 2 tab): (1) OTP theo SĐT qua Zalo trước, fallback SMS (xem
// TenantOtpService) — luôn dùng được, tốn phí gửi mỗi lần; (2) SĐT + mật khẩu tự đặt (xem
// TenantPortalController::updatePassword()) — miễn phí, nhưng khách PHẢI đăng nhập OTP ít nhất 1
// lần trước đó rồi tự đặt mật khẩu mới có. Số điện thoại KHÔNG unique tuyệt đối trong bảng Tenant
// (VD gia đình dùng chung 1 số) — với OTP thì xác thực đúng SĐT + đúng mã là ĐỦ tin cậy, cho chọn hồ
// sơ nếu nhiều người trùng số (selectProfile); với mật khẩu thì SĐT+mật khẩu tự nó đã xác định ĐÚNG
// 1 hồ sơ (mỗi hồ sơ tự đặt mật khẩu RIÊNG), không cần bước chọn hồ sơ.
class TenantAuthController extends Controller
{
    private const PASSWORD_MAX_ATTEMPTS = 5;
    private const PASSWORD_DECAY_SECONDS = 60;

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::guard('tenant')->check()) {
            return redirect()->route('minihouse.portal.dashboard');
        }

        return view('minihouse::portal.auth.login');
    }

    // Đăng nhập bằng SĐT + mật khẩu — KHÔNG dùng Auth::attempt() thẳng vì provider Eloquent mặc định
    // chỉ lấy DÒNG ĐẦU TIÊN khớp "phone" rồi so mật khẩu với đúng dòng đó — sai ngay khi 1 SĐT gắn
    // nhiều hồ sơ (VD người thân dùng chung số) mà người đăng nhập không phải dòng đầu tiên. Tự lấy
    // TẤT CẢ hồ sơ khớp SĐT rồi so mật khẩu với TỪNG hồ sơ, hồ sơ nào khớp thì đăng nhập đúng hồ sơ
    // đó — SĐT+mật khẩu tự nó xác định đúng 1 người, không cần bước "chọn hồ sơ" như luồng OTP.
    public function loginWithPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone'    => ['required', 'string', 'max:20'],
            'password' => ['required', 'string'],
        ]);

        $phone = TenantPortalService::normalizePhone($data['phone']);
        $throttleKey = 'minihouse-portal-password:' . $phone . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::PASSWORD_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors(['password' => 'Bạn đã nhập sai quá nhiều lần — vui lòng thử lại sau ' . $seconds . ' giây, hoặc đăng nhập bằng OTP.'])->withInput();
        }

        $tenant = TenantPortalService::findTenantByPassword($phone, $data['password']);

        if (! $tenant) {
            RateLimiter::hit($throttleKey, self::PASSWORD_DECAY_SECONDS);

            return back()->withErrors(['password' => 'Số điện thoại hoặc mật khẩu không đúng — vui lòng thử lại hoặc đăng nhập bằng OTP.'])->withInput();
        }

        RateLimiter::clear($throttleKey);

        return $this->loginAs($tenant);
    }

    public function requestOtp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $phone = TenantPortalService::normalizePhone($data['phone']);

        $tenant = Tenant::where('phone', $phone)->first();

        if (! $tenant) {
            return back()->withErrors(['phone' => 'Không tìm thấy hồ sơ khách thuê với số điện thoại này — vui lòng liên hệ chủ nhà nếu bạn chắc chắn đây là số đã đăng ký.'])->withInput();
        }

        $result = app(TenantOtpService::class)->generateAndSend($phone, $tenant->fullname);

        if (! $result['sent']) {
            return back()->withErrors(['phone' => $result['reason']])->withInput();
        }

        session(['minihouse_portal_login_phone' => $phone]);

        return redirect()->route('minihouse.portal.login.verify');
    }

    public function showVerify(): View|RedirectResponse
    {
        if (! session('minihouse_portal_login_phone')) {
            return redirect()->route('minihouse.portal.login');
        }

        return view('minihouse::portal.auth.verify', ['phone' => session('minihouse_portal_login_phone')]);
    }

    public function verifyOtp(Request $request): RedirectResponse
    {
        $phone = session('minihouse_portal_login_phone');

        if (! $phone) {
            return redirect()->route('minihouse.portal.login');
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:6'],
        ]);

        $result = app(TenantOtpService::class)->verify($phone, $data['code']);

        if (! $result['valid']) {
            return back()->withErrors(['code' => $result['reason']]);
        }

        $tenants = Tenant::where('phone', $phone)->get();

        if ($tenants->isEmpty()) {
            // Cực hiếm — hồ sơ bị xoá GIỮA lúc khách đang nhập OTP (vài phút). Báo rõ, không đoán mò.
            session()->forget('minihouse_portal_login_phone');

            return redirect()->route('minihouse.portal.login')
                ->withErrors(['phone' => 'Hồ sơ khách thuê không còn tồn tại — vui lòng liên hệ chủ nhà.']);
        }

        session()->forget('minihouse_portal_login_phone');

        if ($tenants->count() === 1) {
            return $this->loginAs($tenants->first());
        }

        // SĐT gắn với NHIỀU hồ sơ khách thuê (VD người thân dùng chung số) — lưu tạm danh sách ID đã
        // XÁC THỰC OTP hợp lệ để chọn, KHÔNG cho chọn ID ngoài danh sách này (xem selectProfile()).
        session(['minihouse_portal_verified_tenant_ids' => $tenants->pluck('id')->all()]);

        return redirect()->route('minihouse.portal.login.select-profile');
    }

    public function showSelectProfile(): View|RedirectResponse
    {
        $ids = session('minihouse_portal_verified_tenant_ids');

        if (! $ids) {
            return redirect()->route('minihouse.portal.login');
        }

        $tenants = Tenant::whereIn('id', $ids)->get();

        return view('minihouse::portal.auth.select-profile', ['tenants' => $tenants]);
    }

    public function selectProfile(Request $request): RedirectResponse
    {
        $ids = session('minihouse_portal_verified_tenant_ids');

        if (! $ids) {
            return redirect()->route('minihouse.portal.login');
        }

        $data = $request->validate(['tenant_id' => ['required', 'integer']]);

        // CHỈ cho chọn ĐÚNG 1 trong số ID đã xác thực OTP hợp lệ ở bước trước — chặn giả mạo đổi
        // tenant_id trên form để đăng nhập nhầm vào hồ sơ người khác không cùng SĐT.
        if (! in_array((int) $data['tenant_id'], $ids, true)) {
            abort(403);
        }

        $tenant = Tenant::findOrFail($data['tenant_id']);
        session()->forget('minihouse_portal_verified_tenant_ids');

        return $this->loginAs($tenant);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('tenant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('minihouse.portal.login');
    }

    private function loginAs(Tenant $tenant): RedirectResponse
    {
        Auth::guard('tenant')->login($tenant, remember: true);
        request()->session()->regenerate();

        return redirect()->route('minihouse.portal.dashboard');
    }
}

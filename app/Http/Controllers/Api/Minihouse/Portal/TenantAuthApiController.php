<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Minihouse\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Services\TenantOtpService;
use Modules\Minihouse\App\Services\TenantPortalService;

// Bản API (token Sanctum, cho app di động/bên thứ 3) của
// Modules\Minihouse\Http\Controllers\Portal\TenantAuthController (web, session) — CÙNG NGHIỆP VỤ,
// CÙNG dùng TenantOtpService/TenantPortalService, chỉ khác cách "nhớ" trạng thái đăng nhập dở dang
// giữa các bước: web dùng session, API (stateless, không có session giữa các request) dùng vé tạm
// lưu trong Cache (xem selectionTicketKey()) — KHÔNG dùng session ở đây dù route có đi qua group
// 'web' middleware (nwidart module mặc định), vì client API thật (app di động) không giữ cookie.
class TenantAuthApiController extends Controller
{
    private const PASSWORD_MAX_ATTEMPTS = 5;
    private const PASSWORD_DECAY_SECONDS = 60;
    private const SELECTION_TICKET_TTL_MINUTES = 5;

    // POST /api/minihouse/portal/otp/request { phone }
    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $phone = TenantPortalService::normalizePhone($data['phone']);
        $tenant = Tenant::where('phone', $phone)->first();

        if (! $tenant) {
            return response()->json(['message' => 'Không tìm thấy hồ sơ khách thuê với số điện thoại này.'], 404);
        }

        $result = app(TenantOtpService::class)->generateAndSend($phone, $tenant->fullname);

        if (! $result['sent']) {
            return response()->json(['message' => $result['reason']], 422);
        }

        return response()->json(['message' => 'Đã gửi mã xác thực.', 'channel' => $result['channel']]);
    }

    // POST /api/minihouse/portal/otp/verify { phone, code }
    // Trả thẳng token nếu SĐT chỉ khớp 1 hồ sơ; trả "selection_ticket" + danh sách hồ sơ nếu SĐT
    // dùng chung (VD người thân) — gọi tiếp selectProfile() bên dưới với ticket đó để hoàn tất.
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'code'  => ['required', 'string', 'max:6'],
        ]);

        $phone = TenantPortalService::normalizePhone($data['phone']);
        $result = app(TenantOtpService::class)->verify($phone, $data['code']);

        if (! $result['valid']) {
            return response()->json(['message' => $result['reason']], 422);
        }

        $tenants = Tenant::where('phone', $phone)->get();

        if ($tenants->isEmpty()) {
            return response()->json(['message' => 'Hồ sơ khách thuê không còn tồn tại — vui lòng liên hệ chủ nhà.'], 404);
        }

        if ($tenants->count() === 1) {
            return $this->tokenResponse($tenants->first());
        }

        // SĐT gắn với NHIỀU hồ sơ — cùng nguyên tắc bảo mật với web (chỉ cho chọn ĐÚNG 1 trong số ID
        // đã xác thực OTP hợp lệ), nhưng lưu bằng Cache (vé dùng 1 lần, hết hạn ngắn) thay vì session.
        $ticket = Str::random(40);
        Cache::put(self::selectionTicketKey($ticket), $tenants->pluck('id')->all(), now()->addMinutes(self::SELECTION_TICKET_TTL_MINUTES));

        return response()->json([
            'requires_selection' => true,
            'selection_ticket'   => $ticket,
            'profiles'           => $tenants->map(fn (Tenant $t) => [
                'id'       => $t->id,
                'fullname' => $t->fullname,
                'room'     => $t->room?->code,
            ]),
        ]);
    }

    // POST /api/minihouse/portal/select-profile { selection_ticket, tenant_id }
    public function selectProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'selection_ticket' => ['required', 'string'],
            'tenant_id'        => ['required', 'integer'],
        ]);

        $ids = Cache::get(self::selectionTicketKey($data['selection_ticket']));

        if (! $ids) {
            return response()->json(['message' => 'Phiên xác thực đã hết hạn — vui lòng đăng nhập lại bằng OTP.'], 422);
        }

        // CHỈ cho chọn ĐÚNG 1 trong số ID đã xác thực OTP hợp lệ — chặn giả mạo đổi tenant_id để
        // đăng nhập nhầm vào hồ sơ người khác không cùng SĐT.
        if (! in_array((int) $data['tenant_id'], $ids, true)) {
            return response()->json(['message' => 'Hồ sơ không hợp lệ.'], 403);
        }

        Cache::forget(self::selectionTicketKey($data['selection_ticket']));

        $tenant = Tenant::findOrFail($data['tenant_id']);

        return $this->tokenResponse($tenant);
    }

    // POST /api/minihouse/portal/login/password { phone, password }
    public function loginWithPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'    => ['required', 'string', 'max:20'],
            'password' => ['required', 'string'],
        ]);

        $phone = TenantPortalService::normalizePhone($data['phone']);
        $throttleKey = 'minihouse-portal-password-api:' . $phone . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::PASSWORD_MAX_ATTEMPTS)) {
            return response()->json(['message' => 'Bạn đã nhập sai quá nhiều lần — vui lòng thử lại sau ' . RateLimiter::availableIn($throttleKey) . ' giây, hoặc đăng nhập bằng OTP.'], 429);
        }

        $tenant = TenantPortalService::findTenantByPassword($phone, $data['password']);

        if (! $tenant) {
            RateLimiter::hit($throttleKey, self::PASSWORD_DECAY_SECONDS);

            return response()->json(['message' => 'Số điện thoại hoặc mật khẩu không đúng.'], 422);
        }

        RateLimiter::clear($throttleKey);

        return $this->tokenResponse($tenant);
    }

    // POST /api/minihouse/portal/logout — chỉ thu hồi ĐÚNG token đang dùng cho request này (không
    // thu hồi hết mọi thiết bị khác đang đăng nhập cùng tài khoản).
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Đã đăng xuất.']);
    }

    private function tokenResponse(Tenant $tenant): JsonResponse
    {
        $token = $tenant->createToken('minihouse-portal')->plainTextToken;

        return response()->json([
            'token'  => $token,
            'tenant' => [
                'id'       => $tenant->id,
                'fullname' => $tenant->fullname,
                'phone'    => $tenant->phone,
            ],
        ]);
    }

    private static function selectionTicketKey(string $ticket): string
    {
        return 'minihouse_tenant_select:' . $ticket;
    }
}

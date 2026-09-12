<?php

namespace Tests\Feature\Minihouse;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Minihouse\App\Models\Tenant;
use Tests\TestCase;

// Gap-analysis phát hiện: luồng đăng nhập Portal khách thuê (OTP theo SĐT + đăng nhập bằng mật khẩu,
// TenantAuthController) chưa có test tự động nào, dù là luồng nhạy cảm về bảo mật (đã có audit trước
// đó xác nhận CODE có cooldown/rate-limit thật — TenantOtpService::RESEND_COOLDOWN_SECONDS,
// RateLimiter trong loginWithPassword — nhưng chưa có test khoá lại các hành vi này). Test này bổ
// sung độ phủ cho đúng phần thiếu, KHÔNG gọi Zalo/SMS thật (không cấu hình ZaloSetting/SmsSetting
// trong môi trường test nên generateAndSend() tự trả sent=false — vẫn kiểm tra được luồng lỗi thân
// thiện; luồng verify OTP thành công seed thẳng Cache theo đúng key TenantOtpService dùng, không cần
// gửi mã thật).
class PortalTenantAuthTest extends TestCase
{
    use DatabaseTransactions;

    public function test_request_otp_rejects_unknown_phone(): void
    {
        $response = $this->post(route('minihouse.portal.login.request-otp'), ['phone' => '0912345678']);

        $response->assertSessionHasErrors('phone');
        $this->assertGuest('tenant');
    }

    public function test_request_otp_for_known_phone_fails_gracefully_when_no_channel_configured(): void
    {
        $tenant = Tenant::create(['fullname' => 'T', 'phone' => '0912345679']);

        $response = $this->post(route('minihouse.portal.login.request-otp'), ['phone' => $tenant->phone]);

        // Môi trường test không cấu hình ZaloSetting/SmsSetting — generateAndSend() tự trả sent=false,
        // không throw ra lỗi 500. Đây là hành vi ĐÚNG (đã audit code xác nhận), test này khoá lại.
        $response->assertSessionHasErrors('phone');
        $this->assertNull(session('minihouse_portal_login_phone'));
    }

    public function test_verify_otp_with_correct_code_logs_tenant_in(): void
    {
        $tenant = Tenant::create(['fullname' => 'T', 'phone' => '0912345680']);

        // Seed thẳng Cache theo đúng key TenantOtpService::cacheKey() dùng — mô phỏng đã gửi mã OTP
        // thành công mà không cần gọi Zalo/SMS thật.
        Cache::put('minihouse_tenant_otp:0912345680', ['code' => '123456', 'attempts' => 0, 'expires_at' => now()->addMinutes(5)->timestamp], now()->addMinutes(5));

        $response = $this->withSession(['minihouse_portal_login_phone' => '0912345680'])
            ->post(route('minihouse.portal.login.verify.submit'), ['code' => '123456']);

        $response->assertRedirect(route('minihouse.portal.dashboard'));
        $this->assertAuthenticatedAs($tenant, 'tenant');
    }

    public function test_verify_otp_with_wrong_code_does_not_log_in_and_decrements_attempts(): void
    {
        Tenant::create(['fullname' => 'T', 'phone' => '0912345681']);
        Cache::put('minihouse_tenant_otp:0912345681', ['code' => '123456', 'attempts' => 0, 'expires_at' => now()->addMinutes(5)->timestamp], now()->addMinutes(5));

        $response = $this->withSession(['minihouse_portal_login_phone' => '0912345681'])
            ->post(route('minihouse.portal.login.verify.submit'), ['code' => '000000']);

        $response->assertSessionHasErrors('code');
        $this->assertGuest('tenant');

        $data = Cache::get('minihouse_tenant_otp:0912345681');
        $this->assertSame(1, $data['attempts']);
    }

    public function test_verify_otp_locks_out_after_max_wrong_attempts(): void
    {
        Tenant::create(['fullname' => 'T', 'phone' => '0912345682']);
        // TenantOtpService::verify() kiểm tra attempts >= MAX_ATTEMPTS NGAY LÚC VÀO hàm (trước khi so
        // mã) — seed thẳng attempts=5 (đã đạt ngưỡng từ lần gọi trước đó) để đúng nhánh khoá hẳn, mã
        // bị huỷ ngay cả khi lần này đoán ĐÚNG mã.
        Cache::put('minihouse_tenant_otp:0912345682', ['code' => '123456', 'attempts' => 5, 'expires_at' => now()->addMinutes(5)->timestamp], now()->addMinutes(5));

        $response = $this->withSession(['minihouse_portal_login_phone' => '0912345682'])
            ->post(route('minihouse.portal.login.verify.submit'), ['code' => '123456']);

        $response->assertSessionHasErrors('code');
        $this->assertGuest('tenant');
        $this->assertNull(Cache::get('minihouse_tenant_otp:0912345682'));
    }

    public function test_password_login_with_correct_password_logs_in(): void
    {
        $tenant = Tenant::create(['fullname' => 'T', 'phone' => '0912345683', 'password' => 'secret123']);

        $response = $this->post(route('minihouse.portal.login.password'), [
            'phone' => $tenant->phone, 'password' => 'secret123',
        ]);

        $response->assertRedirect(route('minihouse.portal.dashboard'));
        $this->assertAuthenticatedAs($tenant, 'tenant');
    }

    public function test_password_login_with_wrong_password_fails(): void
    {
        Tenant::create(['fullname' => 'T', 'phone' => '0912345684', 'password' => 'secret123']);

        $response = $this->post(route('minihouse.portal.login.password'), [
            'phone' => '0912345684', 'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest('tenant');
    }

    public function test_password_login_is_rate_limited_after_max_attempts(): void
    {
        Tenant::create(['fullname' => 'T', 'phone' => '0912345685', 'password' => 'secret123']);
        RateLimiter::clear('minihouse-portal-password:0912345685|127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('minihouse.portal.login.password'), [
                'phone' => '0912345685', 'password' => 'wrong-password',
            ]);
        }

        // Lần thứ 6 (dù gõ ĐÚNG mật khẩu) phải bị chặn bởi rate limit, không được đăng nhập.
        $response = $this->post(route('minihouse.portal.login.password'), [
            'phone' => '0912345685', 'password' => 'secret123',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest('tenant');
    }

    public function test_password_login_with_phone_shared_by_two_profiles_matches_correct_one(): void
    {
        // Cùng 1 SĐT gắn 2 hồ sơ (gia đình dùng chung số) — mỗi hồ sơ tự đặt mật khẩu RIÊNG, đăng
        // nhập phải khớp ĐÚNG hồ sơ có mật khẩu đó, không phải luôn lấy hồ sơ đầu tiên.
        Tenant::create(['fullname' => 'Nguoi thu nhat', 'phone' => '0912345686', 'password' => 'password-one']);
        $second = Tenant::create(['fullname' => 'Nguoi thu hai', 'phone' => '0912345686', 'password' => 'password-two']);

        $response = $this->post(route('minihouse.portal.login.password'), [
            'phone' => '0912345686', 'password' => 'password-two',
        ]);

        $response->assertRedirect(route('minihouse.portal.dashboard'));
        $this->assertAuthenticatedAs($second, 'tenant');
    }
}

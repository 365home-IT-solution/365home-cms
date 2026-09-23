<?php

namespace Tests\Feature;

use App\Services\ZaloOtpService;
use App\Settings\ZaloSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Bug thật đã gặp trên production (2026-09-22): Zalo trả "Access token invalid" (-124) cho MỌI lần
// gửi OTP trong cả tiếng đồng hồ liền, vì sendViaZns() gọi getAccessToken() đúng 1 lần rồi fail hẳn
// khi Zalo từ chối token — không có cách nào tự phục hồi tới khi Cache tự hết hạn. Controller lại
// dùng chung 1 thông báo cho MỌI lỗi gửi ("Số điện thoại chưa đăng ký Zalo...") nên lỗi hạ tầng này
// bị hiểu nhầm thành lỗi của từng số điện thoại. Test này khoá lại hành vi tự phục hồi mới: gặp -124
// thì ép refresh token rồi thử gửi lại đúng 1 lần trước khi báo fail.
class ZaloOtpServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('zalo_access_token');
        config([
            'app.env'                => 'testing',
            'app.otp_bypass_enabled' => false,
            'zalo.app_id'            => 'test-app-id',
            'zalo.app_secret'        => 'test-app-secret',
            'zalo.otp_template_id'   => 'test-template-id',
        ]);

        $settings = app(ZaloSettings::class);
        $settings->access_token = null;
        $settings->refresh_token = 'old-refresh-token';
        $settings->access_token_expires_at = null;
        $settings->save();
    }

    public function test_send_retries_once_and_succeeds_after_invalid_token(): void
    {
        Cache::put('zalo_access_token', 'stale-token', now()->addHour());

        Http::fake([
            'business.openapi.zalo.me/*' => Http::sequence()
                ->push(['error' => -124, 'message' => 'Access token invalid'], 200)
                ->push(['error' => 0, 'message' => 'Success'], 200),
            'oauth.zaloapp.com/*' => Http::response(['access_token' => 'brand-new-token', 'expires_in' => 3600], 200),
        ]);

        $sent = app(ZaloOtpService::class)->send('0912345678');

        $this->assertTrue($sent);
        Http::assertSentCount(3); // 1 ZNS thất bại + 1 refresh + 1 ZNS thành công
        $this->assertSame('brand-new-token', Cache::get('zalo_access_token'));
    }

    public function test_send_fails_if_still_invalid_after_retry(): void
    {
        Cache::put('zalo_access_token', 'stale-token', now()->addHour());

        Http::fake([
            'business.openapi.zalo.me/*' => Http::response(['error' => -124, 'message' => 'Access token invalid'], 200),
            'oauth.zaloapp.com/*'        => Http::response(['access_token' => 'still-bad-token', 'expires_in' => 3600], 200),
        ]);

        $sent = app(ZaloOtpService::class)->send('0912345678');

        $this->assertFalse($sent);
    }

    public function test_send_succeeds_without_retry_when_token_is_valid(): void
    {
        Cache::put('zalo_access_token', 'good-token', now()->addHour());

        Http::fake([
            'business.openapi.zalo.me/*' => Http::response(['error' => 0, 'message' => 'Success'], 200),
        ]);

        $sent = app(ZaloOtpService::class)->send('0912345678');

        $this->assertTrue($sent);
        Http::assertSentCount(1);
    }
}

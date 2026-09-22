<?php

namespace Tests\Feature\Minihouse;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Minihouse\App\Models\ZaloSetting;
use Modules\Minihouse\App\Services\MinihouseZaloTokenService;
use Tests\TestCase;

// Khoá lại hành vi thử lại khi Zalo trả về phản hồi không đọc được (mất mạng/timeout giữa chừng) —
// bug thật đã gặp trên production (xem log 2026-09-11): 1 lần gọi lỗi mạng làm refresh_token "chết"
// vĩnh viễn vì Zalo thu hồi token cũ ngay khi nhận request, không cần chờ app đọc được phản hồi.
class MinihouseZaloTokenServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('minihouse_zalo_access_token');
        Cache::forget('minihouse_zalo_refresh_token');
    }

    private function seedSettings(): ZaloSetting
    {
        $settings = ZaloSetting::current();
        $settings->app_id = 'test-app-id';
        $settings->app_secret = 'test-app-secret';
        $settings->refresh_token = 'old-refresh-token';
        $settings->access_token = null;
        $settings->access_token_expires_at = null;
        $settings->save();

        return $settings;
    }

    public function test_retries_after_malformed_response_then_succeeds(): void
    {
        $this->seedSettings();

        Http::fake([
            'oauth.zaloapp.com/*' => Http::sequence()
                ->push('not-json-at-all', 200)
                ->push(['access_token' => 'new-access-token', 'refresh_token' => 'new-refresh-token', 'expires_in' => 3600], 200),
        ]);

        $token = (new MinihouseZaloTokenService())->getAccessToken();

        $this->assertSame('new-access-token', $token);
        Http::assertSentCount(2);

        $fresh = ZaloSetting::current();
        $this->assertSame('new-access-token', $fresh->access_token);
        $this->assertSame('new-refresh-token', $fresh->refresh_token);
    }

    public function test_does_not_retry_on_definite_rejection(): void
    {
        $this->seedSettings();

        Http::fake([
            'oauth.zaloapp.com/*' => Http::response(['error' => -14014, 'error_name' => 'Invalid refresh token.'], 200),
        ]);

        try {
            (new MinihouseZaloTokenService())->getAccessToken();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            // expected
        }

        Http::assertSentCount(1);
    }

    public function test_gives_up_after_max_attempts_of_malformed_responses(): void
    {
        $this->seedSettings();

        Http::fake([
            'oauth.zaloapp.com/*' => Http::response('not-json-at-all', 200),
        ]);

        try {
            (new MinihouseZaloTokenService())->getAccessToken();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            // expected
        }

        Http::assertSentCount(3);
    }

    // Van xả khẩn cấp (MINIHOUSE_ZALO_REFRESH_TOKEN_OVERRIDE, xem config/minihouse_zalo.php) — dùng
    // khi refresh_token trong DB đã chết mà chưa kịp vào panel dán lại. Khoá lại: (1) override được
    // gửi lên Zalo thay vì refresh_token (đã chết) đang lưu trong DB, (2) token MỚI Zalo cấp lại vẫn
    // lưu THẲNG vào DB như bình thường — request sau không cần override nữa vẫn refresh được.
    public function test_refresh_token_override_is_used_instead_of_dead_db_value(): void
    {
        $settings = $this->seedSettings();
        $settings->update(['refresh_token' => 'dead-token-in-db']);

        config(['minihouse_zalo.refresh_token_override' => 'override-token-from-env']);

        Http::fake([
            'oauth.zaloapp.com/*' => Http::response(['access_token' => 'new-access-token', 'refresh_token' => 'new-refresh-token', 'expires_in' => 3600], 200),
        ]);

        $token = (new MinihouseZaloTokenService())->getAccessToken();

        $this->assertSame('new-access-token', $token);
        Http::assertSent(fn ($request) => $request['refresh_token'] === 'override-token-from-env');

        $this->assertSame('new-refresh-token', ZaloSetting::current()->refresh_token);
    }
}

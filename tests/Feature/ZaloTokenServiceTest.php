<?php

namespace Tests\Feature;

use App\Services\ZaloTokenService;
use App\Settings\ZaloSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Bug thật đã gặp (2026-09-22, log "Access token invalid" -124 ngay sau khi hợp nhất
// Modules\Minihouse\App\Services\MinihouseZaloTokenService vào dùng chung service này): Cache::lock()
// chỉ loại trừ lẫn nhau đúng nghĩa NẾU mọi tiến trình gọi getAccessToken() dùng CHUNG 1 backend cache
// — web/queue/cron/MiniHouse có thể chạy khác container, CACHE_DRIVER không dùng chung thì 2 bên
// cùng refresh gần lúc nhau, bên sau cầm token đã bị Zalo thu hồi bởi bên trước. Đổi sang khoá DB
// thật (lockForUpdate() trên bảng settings, group='zalo') — test này khoá lại hành vi cơ bản (chưa
// test được race đa tiến trình thật trong 1 lượt PHPUnit, chỉ khoá lại đường đi đơn luồng không vỡ).
class ZaloTokenServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('zalo_access_token');
        Cache::forget('zalo_refresh_token');
        config(['zalo.app_id' => 'test-app-id', 'zalo.app_secret' => 'test-app-secret']);
    }

    private function seedSettings(): ZaloSettings
    {
        $settings = app(ZaloSettings::class);
        $settings->access_token = null;
        $settings->refresh_token = 'old-refresh-token';
        $settings->access_token_expires_at = null;
        $settings->save();

        return $settings;
    }

    public function test_refreshes_and_persists_new_token_to_settings(): void
    {
        $this->seedSettings();

        Http::fake([
            'oauth.zaloapp.com/*' => Http::response(['access_token' => 'new-access-token', 'refresh_token' => 'new-refresh-token', 'expires_in' => 3600], 200),
        ]);

        $token = app(ZaloTokenService::class)->getAccessToken();

        $this->assertSame('new-access-token', $token);
        Http::assertSent(fn ($request) => $request['refresh_token'] === 'old-refresh-token');

        $fresh = app(ZaloSettings::class);
        $this->assertSame('new-access-token', $fresh->access_token);
        $this->assertSame('new-refresh-token', $fresh->refresh_token);
        $this->assertSame('new-access-token', Cache::get('zalo_access_token'));
    }

    public function test_uses_cached_token_without_calling_zalo_at_all(): void
    {
        Cache::put('zalo_access_token', 'cached-token', now()->addHour());
        Http::fake();

        $token = app(ZaloTokenService::class)->getAccessToken();

        $this->assertSame('cached-token', $token);
        Http::assertNothingSent();
    }

    public function test_falls_back_to_db_stored_access_token_when_cache_is_empty_but_not_expired(): void
    {
        $settings = $this->seedSettings();
        $settings->access_token = 'db-access-token';
        $settings->access_token_expires_at = now()->addHour()->timestamp;
        $settings->save();

        Http::fake();

        $token = app(ZaloTokenService::class)->getAccessToken();

        $this->assertSame('db-access-token', $token);
        Http::assertNothingSent();
    }

    // Bug thật đã sửa: refresh() từng ưu tiên đọc Cache::get('zalo_refresh_token') trước
    // ZaloSettings->refresh_token — nếu Cache của tiến trình A còn giữ token CŨ (đã bị thu hồi bởi
    // tiến trình B refresh trước đó và ghi DB mới) thì A vẫn gửi nhầm token chết lên Zalo. Giờ luôn
    // ưu tiên đọc DB (đã khoá lockForUpdate(), luôn mới nhất).
    public function test_refresh_prioritizes_db_refresh_token_over_stale_cache(): void
    {
        $this->seedSettings();
        Cache::put('zalo_refresh_token', 'stale-cache-token', now()->addMonths(3));

        Http::fake([
            'oauth.zaloapp.com/*' => Http::response(['access_token' => 'ok', 'expires_in' => 3600], 200),
        ]);

        app(ZaloTokenService::class)->getAccessToken();

        Http::assertSent(fn ($request) => $request['refresh_token'] === 'old-refresh-token');
    }

    // Bug thật đã sửa (2026-09-22): Zalo có thể thu hồi access_token ngoài dự kiến dù Cache/DB vẫn
    // ghi "chưa hết hạn" theo thời gian — trước đây getAccessToken() không có cách nào ép bỏ qua
    // Cache/DB để refresh lại thật sự, khiến nơi gọi (ZaloOtpService/MinihouseZaloService) cứ nhận
    // lại đúng token đã bị từ chối cho tới khi Cache tự hết hạn (tối đa 1 giờ).
    public function test_force_refresh_ignores_cache_and_unexpired_db_token(): void
    {
        Cache::put('zalo_access_token', 'stale-cached-token', now()->addHour());
        $settings = $this->seedSettings();
        $settings->access_token = 'stale-db-token';
        $settings->access_token_expires_at = now()->addHour()->timestamp;
        $settings->save();

        Http::fake([
            'oauth.zaloapp.com/*' => Http::response(['access_token' => 'brand-new-token', 'expires_in' => 3600], 200),
        ]);

        $token = app(ZaloTokenService::class)->getAccessToken(forceRefresh: true);

        $this->assertSame('brand-new-token', $token);
        $this->assertSame('brand-new-token', Cache::get('zalo_access_token'));
        Http::assertSent(fn ($request) => $request['refresh_token'] === 'old-refresh-token');
    }

    public function test_is_invalid_token_error_matches_known_zalo_codes(): void
    {
        $this->assertTrue(ZaloTokenService::isInvalidTokenError(-124));
        $this->assertTrue(ZaloTokenService::isInvalidTokenError('-124'));
        $this->assertFalse(ZaloTokenService::isInvalidTokenError(0));
        $this->assertFalse(ZaloTokenService::isInvalidTokenError(null));
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\AdminNotificationRealtimeService;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

// App tự báo lỗi (POST /api/app-error-reports): công khai, luôn 202 khi body hợp lệ, thông báo Super Admin và không dội lại cùng một lỗi.
class AppErrorReportTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private array $adminHeaders;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();
        RateLimiter::clear('app-error-notify');
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->mock(FcmService::class, fn ($mock) => $mock->shouldReceive('sendToUsers'));
        $this->mock(AdminNotificationRealtimeService::class, fn ($mock) => $mock->shouldReceive('broadcastNew'));

        $this->admin = User::create(['fullname' => 'Super Admin Test', 'email' => 'app-error-admin@example.test', 'password' => 'secret-secret']);
        $this->admin->assignRole(config('filament-shield.super_admin.name'));
        $this->adminHeaders = ['Authorization' => 'Bearer ' . $this->admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function payload(array $reports): array
    {
        return [
            'app'     => ['platform' => 'android', 'os_version' => '34', 'version' => '1.0.13', 'runtime_version' => '1.0.13', 'update_id' => '4a1c', 'channel' => 'production'],
            'user'    => ['id' => 123, 'role' => 'admin'],
            'reports' => $reports,
        ];
    }

    private function apiReport(array $overrides = []): array
    {
        return $overrides + [
            'kind' => 'api', 'area' => 'homestay', 'route' => '/admin/order/17870331747561', 'method' => 'GET', 'endpoint' => '/admin/orders/{id}',
            'url' => 'https://365home.vn/api/admin/orders/17870331747561?page=2', 'status' => 500, 'message' => 'Server Error',
            'detail' => '{"message":"Server Error"}', 'count' => 3, 'first_at' => '2026-10-06T03:00:00.000Z', 'last_at' => '2026-10-06T03:04:12.000Z',
        ];
    }

    private function appErrors(): \Illuminate\Support\Collection
    {
        return collect($this->getJson('/api/admin/notifications?per_page=50', $this->adminHeaders)->assertOk()->json('data'))->where('type', 'app_error')->values();
    }

    public function test_guest_report_is_accepted_and_notifies_super_admins_once_per_error(): void
    {
        $crash = ['kind' => 'crash', 'area' => 'client', 'route' => '/room/01k9xe994aamcwz1rwn470yve3', 'message' => "TypeError: Cannot read property 'price' of undefined",
            'detail' => 'at RoomDetail (app:///index.bundle:1234)', 'count' => 1, 'first_at' => '2026-10-06T03:01:00.000Z', 'last_at' => '2026-10-06T03:01:00.000Z'];

        $this->postJson('/api/app-error-reports', $this->payload([$this->apiReport(), $crash]))->assertStatus(202)->assertExactJson(['ok' => true]);

        $items = $this->appErrors();
        $this->assertCount(2, $items);
        $api = $items->firstWhere('title', 'App báo lỗi API: GET /admin/orders/{id} → 500');
        $this->assertNotNull($api);
        // Không có token: người gửi chỉ là thông tin app tự khai.
        $this->assertStringContainsString('admin #123 (app tự khai', $api['body']);
        $this->assertStringContainsString('3 lần', $api['body']);
        $this->assertNotNull($items->first(fn ($item) => str_starts_with($item['title'], 'App bị crash: TypeError')));

        // Máy khác báo lại đúng lỗi đó trong 10 phút: vẫn 202 nhưng không thêm thông báo; lỗi khác thì có.
        $this->postJson('/api/app-error-reports', $this->payload([$this->apiReport(), $this->apiReport(['status' => 502])]))->assertStatus(202);
        $this->assertCount(3, $this->appErrors());
    }

    public function test_reporter_comes_from_the_token_not_the_body(): void
    {
        $this->postJson('/api/app-error-reports', $this->payload([$this->apiReport()]), $this->adminHeaders)->assertStatus(202);

        $body = $this->appErrors()->first()['body'];
        $this->assertStringContainsString("Nhân viên Super Admin Test (#{$this->admin->id})", $body);
        $this->assertStringNotContainsString('#123', $body);
    }

    public function test_invalid_body_is_rejected_and_notifications_are_capped_per_hour(): void
    {
        $this->postJson('/api/app-error-reports', ['reports' => []])->assertStatus(422);
        $this->postJson('/api/app-error-reports', $this->payload(array_fill(0, 21, $this->apiReport())))->assertStatus(422);

        // 2 request × 20 lỗi khác nhau = 40 lỗi mới, nhưng chỉ tối đa 30 thông báo/giờ.
        foreach ([0, 1] as $batch) {
            $reports = array_map(fn ($i) => $this->apiReport(['endpoint' => "/x/{$batch}/{$i}"]), range(1, 20));
            $this->postJson('/api/app-error-reports', $this->payload($reports))->assertStatus(202);
        }
        $this->assertSame(30, $this->getJson('/api/admin/notifications?per_page=1', $this->adminHeaders)->json('meta.total'));
    }
}

<?php

namespace Tests\Feature\Api;

use App\Filament\Resources\AppErrorResource;
use App\Filament\Resources\AppErrorResource\Pages\ListAppErrors;
use App\Models\AppError;
use App\Models\User;
use App\Services\FcmService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

// App tự báo lỗi (POST /api/app-error-reports): công khai, luôn 202 khi body hợp lệ, lưu vào danh sách lỗi riêng của Super Admin (GET /api/admin/app-errors) chứ không
// vào thông báo chính, gộp lỗi trùng và chỉ push khi lỗi mới xuất hiện.
class AppErrorReportTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private array $adminHeaders;

    private int $pushes = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();
        RateLimiter::clear('app-error-notify');
        RateLimiter::clear('app-error-new');
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->mock(FcmService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->andReturnUsing(function () {
            $this->pushes++;
        }));

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

    private function appErrors(string $query = ''): \Illuminate\Support\Collection
    {
        return collect($this->getJson('/api/admin/app-errors?per_page=50' . $query, $this->adminHeaders)->assertOk()->json('data'));
    }

    public function test_guest_report_is_stored_once_per_error_and_kept_out_of_notifications(): void
    {
        $crash = ['kind' => 'crash', 'area' => 'client', 'route' => '/room/01k9xe994aamcwz1rwn470yve3', 'message' => "TypeError: Cannot read property 'price' of undefined",
            'detail' => 'at RoomDetail (app:///index.bundle:1234)', 'count' => 1, 'first_at' => '2026-10-06T03:01:00.000Z', 'last_at' => '2026-10-06T03:01:00.000Z'];

        $this->postJson('/api/app-error-reports', $this->payload([$this->apiReport(), $crash]))->assertStatus(202)->assertExactJson(['ok' => true]);

        $items = $this->appErrors();
        $this->assertCount(2, $items);
        $api = $items->firstWhere('title', 'App báo lỗi API: GET /admin/orders/{id} → 500');
        $this->assertNotNull($api);
        // Không có token: người gửi chỉ là thông tin app tự khai.
        $this->assertStringContainsString('admin #123 (app tự khai', $api['reporter']);
        $this->assertSame(3, $api['count']);
        $this->assertSame('1.0.13', $api['app']['version']);
        $this->assertNotNull($items->first(fn ($item) => str_starts_with($item['title'], 'App bị crash: TypeError')));
        $this->assertSame(2, $this->pushes);

        // Máy khác báo lại đúng lỗi đó: vẫn 202, cộng dồn số lần chứ không thêm dòng và không push; lỗi khác thì có.
        $this->postJson('/api/app-error-reports', $this->payload([$this->apiReport(), $this->apiReport(['status' => 502])]))->assertStatus(202);
        $items = $this->appErrors();
        $this->assertCount(3, $items);
        $this->assertSame(6, $items->firstWhere('id', $api['id'])['count']);
        $this->assertSame(3, $this->pushes);

        // Danh sách thông báo chính không dính lỗi app.
        $this->assertSame(0, $this->getJson('/api/admin/notifications?per_page=1', $this->adminHeaders)->assertOk()->json('meta.total'));
    }

    public function test_reporter_comes_from_the_token_not_the_body(): void
    {
        $this->postJson('/api/app-error-reports', $this->payload([$this->apiReport()]), $this->adminHeaders)->assertStatus(202);

        $reporter = $this->appErrors()->first()['reporter'];
        $this->assertStringContainsString("Nhân viên Super Admin Test (#{$this->admin->id})", $reporter);
        $this->assertStringNotContainsString('#123', $reporter);
    }

    public function test_resolved_error_reopens_and_pushes_again_when_reported_again(): void
    {
        $this->postJson('/api/app-error-reports', $this->payload([$this->apiReport()]))->assertStatus(202);
        $id = $this->appErrors()->first()['id'];

        $this->getJson("/api/admin/app-errors/{$id}", $this->adminHeaders)->assertOk()->assertJsonPath('data.detail', '{"message":"Server Error"}');
        $this->postJson("/api/admin/app-errors/{$id}/resolve", [], $this->adminHeaders)->assertOk()->assertJsonPath('data.is_resolved', true);
        $this->assertCount(0, $this->appErrors());
        $this->assertCount(1, $this->appErrors('&status=resolved'));

        $this->postJson('/api/app-error-reports', $this->payload([$this->apiReport()]))->assertStatus(202);
        $reopened = $this->appErrors()->first();
        $this->assertSame($id, $reopened['id']);
        $this->assertFalse($reopened['is_resolved']);
        $this->assertSame(6, $reopened['count']);
        $this->assertSame(2, $this->pushes);

        $this->deleteJson("/api/admin/app-errors/{$id}", [], $this->adminHeaders)->assertOk();
        $this->getJson("/api/admin/app-errors/{$id}", $this->adminHeaders)->assertStatus(404);
    }

    public function test_errors_are_grouped_into_modules_and_can_be_filtered(): void
    {
        $this->postJson('/api/app-error-reports', $this->payload([
            $this->apiReport(),
            $this->apiReport(['endpoint' => null, 'url' => 'https://365home.vn/api/v1/payment/payos/123']),
            $this->apiReport(['endpoint' => '/admin/minihouse/rooms/{id}']),
            $this->apiReport(['endpoint' => '/something-new']),
            ['kind' => 'crash', 'area' => 'client', 'route' => '/(tabs)/order/17870331747561', 'message' => 'TypeError: x is undefined'],
        ]))->assertStatus(202);

        $this->assertEqualsCanonicalizing(['orders', 'orders', 'payments', 'rooms', 'other'], $this->appErrors()->pluck('module')->all());

        $orders = $this->appErrors('&module=orders');
        $this->assertCount(2, $orders);
        $this->assertSame('Đơn hàng', $orders->first()['module_label']);

        $this->postJson("/api/admin/app-errors/{$orders->first()['id']}/resolve", [], $this->adminHeaders)->assertOk();

        $modules = collect($this->getJson('/api/admin/app-errors/modules', $this->adminHeaders)->assertOk()->json('data'))->keyBy('key');
        $this->assertSame(['key' => 'orders', 'label' => 'Đơn hàng', 'open_count' => 1, 'total' => 2], $modules['orders']);
        $this->assertSame(1, $modules['payments']['open_count']);
        $this->assertSame(0, $modules['chat']['total']);
    }

    public function test_only_super_admin_can_read_app_errors(): void
    {
        $staff   = User::create(['fullname' => 'Staff Test', 'email' => 'app-error-staff@example.test', 'password' => 'secret-secret']);
        $headers = ['Authorization' => 'Bearer ' . $staff->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

        $this->getJson('/api/admin/app-errors', $headers)->assertStatus(403);
    }

    public function test_web_admin_page_lists_errors_for_super_admin_only(): void
    {
        $this->postJson('/api/app-error-reports', $this->payload([$this->apiReport()]))->assertStatus(202);
        $error = AppError::firstOrFail();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($this->admin);
        $this->assertTrue(AppErrorResource::canViewAny());
        Livewire::test(ListAppErrors::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$error])
            ->callTableAction('resolve', $error)
            // Mặc định chỉ lọc lỗi đang mở nên lỗi vừa xử lý rời khỏi bảng.
            ->assertCanNotSeeTableRecords([$error]);
        $this->assertNotNull($error->fresh()->resolved_at);

        $this->actingAs(User::create(['fullname' => 'Staff Test', 'email' => 'app-error-staff-web@example.test', 'password' => 'secret-secret']));
        $this->assertFalse(AppErrorResource::canViewAny());
        Livewire::test(ListAppErrors::class)->assertForbidden();
    }

    public function test_invalid_body_is_rejected_and_pushes_are_capped_per_hour(): void
    {
        $this->postJson('/api/app-error-reports', ['reports' => []])->assertStatus(422);
        $this->postJson('/api/app-error-reports', $this->payload(array_fill(0, 21, $this->apiReport())))->assertStatus(422);

        // 2 request × 20 lỗi khác nhau = 40 lỗi mới đều được lưu, nhưng chỉ tối đa 30 push/giờ.
        foreach ([0, 1] as $batch) {
            $reports = array_map(fn ($i) => $this->apiReport(['endpoint' => "/x/{$batch}/{$i}"]), range(1, 20));
            $this->postJson('/api/app-error-reports', $this->payload($reports))->assertStatus(202);
        }
        $this->assertSame(40, $this->getJson('/api/admin/app-errors?per_page=1', $this->adminHeaders)->json('meta.total'));
        $this->assertSame(30, $this->pushes);
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Partner;
use App\Models\PartnerStatusLog;
use App\Models\User;
use App\Services\Payment\PartnerPayOsChannelService;
use App\Services\Payment\PayOsAccountResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\BranchPayOsAccount;
use Modules\Payment\Entities\Order;
use Tests\TestCase;

// KÊNH PAYOS THEO ĐỐI TÁC: lưu có gọi thử PayOS + đối chiếu chủ tài khoản, không lộ khoá, và đơn của mọi chi nhánh thuộc đối tác
// dùng kênh của đối tác (chi nhánh có ghi đè thì theo chi nhánh; chưa cấu hình thì 365home thu hộ).
class PartnerPaymentChannelTest extends TestCase
{
    use DatabaseTransactions;

    private Partner $partner;

    private Category $branch;

    private array $adminHeaders;

    private User $admin;

    private array $ownerHeaders;

    private array $staffHeaders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        config(['payos.client_id' => 'global-client', 'payos.api_key' => 'global-api', 'payos.checksum_key' => 'global-checksum']);

        $this->partner = Partner::create([
            'partner_type' => Partner::TYPE_HOMESTAY, 'name' => 'Homestay Kênh PayOS', 'legal_name' => 'Homestay Kênh PayOS', 'phone' => '0970000801',
            'status' => true, 'verification_status' => 'approved', 'contract_status' => 'active', 'bank_account_holder' => 'Nguyễn Văn An',
        ]);
        $this->branch = Category::create(['name' => $name = 'Chi nhanh kenh ' . uniqid(), 'slug' => Str::slug($name), 'category_type' => 'product', 'parent_id' => null, 'partner_id' => $this->partner->id, 'status' => true]);

        $admin = User::create(['fullname' => 'Super Admin Test', 'email' => 'channel-admin@example.test', 'password' => 'secret-secret']);
        $admin->assignRole(config('filament-shield.super_admin.name'));
        $owner = User::create(['fullname' => 'Chủ đối tác', 'email' => 'channel-owner@example.test', 'password' => 'secret-secret', 'partner_id' => $this->partner->id]);

        $this->adminHeaders = ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
        $owner->assignRole(config('permission.models.role')::firstOrCreate(['name' => 'partner', 'guard_name' => 'web']));
        $staff = User::create(['fullname' => 'Nhân viên', 'email' => 'channel-staff@example.test', 'password' => 'secret-secret', 'partner_id' => $this->partner->id]);
        $this->ownerHeaders = ['Authorization' => 'Bearer ' . $owner->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
        $this->staffHeaders = ['Authorization' => 'Bearer ' . $staff->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
        $this->mock(\App\Services\FcmService::class, fn ($mock) => $mock->shouldReceive('sendToUsers'));
        $this->mock(\App\Services\AdminNotificationRealtimeService::class, fn ($mock) => $mock->shouldReceive('broadcastNew'));
        $this->admin = $admin;
    }

    /** PayOS giả: mỗi lần gọi thử trả lần lượt một tên chủ tài khoản cho trước (hoặc ném lỗi); không đăng ký webhook thật. */
    private function fakePayOs(string|\Throwable ...$probes): void
    {
        $this->partialMock(PartnerPayOsChannelService::class, function ($mock) use ($probes) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('probe')->times(count($probes))->andReturnUsing(function () use (&$probes) {
                $next = array_shift($probes);

                return $next instanceof \Throwable ? throw $next : $next;
            });
            $mock->shouldReceive('confirmWebhook')->andReturn(false);
        });
    }
    // Mỗi request trong cùng một test dùng token riêng: bỏ user đã nhớ ở guard để request sau không dùng lại danh tính request trước.
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/admin/partners/{$this->partner->id}/payment-channel{$suffix}";
    }

    private function keys(array $override = []): array
    {
        return $override + ['client_id' => 'partner-client', 'api_key' => 'partner-api', 'checksum_key' => 'partner-checksum'];
    }

    public function test_saving_verifies_the_channel_and_never_returns_secrets(): void
    {
        $this->fakePayOs('NGUYEN VAN AN');

        // Chưa cấu hình: 365home thu hộ.
        $this->getJson($this->url(), $this->adminHeaders)->assertOk()->assertJsonPath('data.payos_configured', false)->assertJsonPath('data.collected_by', 'platform');

        $response = $this->postJson($this->url(), $this->keys(), $this->adminHeaders)->assertOk()
            ->assertJsonPath('data.payos_configured', true)
            ->assertJsonPath('data.payos_client_id', 'partner-client')
            ->assertJsonPath('data.collected_by', 'partner')
            ->assertJsonPath('data.account_holder', 'NGUYEN VAN AN');
        $this->assertStringNotContainsString('partner-api', $response->getContent());
        $this->assertStringNotContainsString('partner-checksum', $response->getContent());

        // Khoá được mã hoá trong DB; đổi kênh có ghi lịch sử hồ sơ nhưng không kèm khoá.
        $this->assertDatabaseMissing('partner_payos_accounts', ['api_key' => 'partner-api']);
        $log = PartnerStatusLog::where('partner_id', $this->partner->id)->latest('id')->first();
        $this->assertStringContainsString('Đổi kênh PayOS', $log->note);
        $this->assertStringNotContainsString('partner-api', $log->note);

        // Nhân viên của đối tác xem được nhưng không sửa được; tắt kênh (không gửi khoá) giữ nguyên khoá và không gọi thử lại.
        $this->getJson($this->url(), $this->staffHeaders)->assertOk()->assertJsonPath('data.payos_configured', true);
        $this->postJson($this->url(), $this->keys(), $this->staffHeaders)->assertForbidden();
        $this->postJson($this->url(), ['is_active' => false], $this->adminHeaders)->assertOk()
            ->assertJsonPath('data.payos_configured', true)->assertJsonPath('data.collected_by', 'platform');
        $this->assertSame('partner-api', $this->partner->payOsAccount()->first()->api_key);
    }

    public function test_partner_owner_can_set_up_the_channel_and_super_admins_are_notified(): void
    {
        $this->fakePayOs('NGUYEN VAN AN');

        // Mặc định chỉ 365home nhập: chủ đối tác bị chặn cho tới khi Super Admin bật quyền cho đối tác này (chủ đối tác không tự bật được).
        $this->getJson($this->url(), $this->ownerHeaders)->assertOk()->assertJsonPath('data.partner_setup_allowed', false)->assertJsonPath('data.can_manage', false);
        $this->postJson($this->url(), $this->keys(), $this->ownerHeaders)->assertForbidden();
        $this->putJson($this->url('/permission'), ['partner_setup_allowed' => true], $this->ownerHeaders)->assertForbidden();
        $this->putJson($this->url('/permission'), ['partner_setup_allowed' => true], $this->adminHeaders)->assertOk()->assertJsonPath('data.partner_setup_allowed', true);

        // Đã bật: chủ đối tác nhập được, nhân viên vẫn không.
        $this->getJson($this->url(), $this->ownerHeaders)->assertJsonPath('data.can_manage', true);
        $this->getJson($this->url(), $this->staffHeaders)->assertJsonPath('data.can_manage', false);
        $this->postJson($this->url(), $this->keys(), $this->staffHeaders)->assertForbidden();
        $this->postJson($this->url(), $this->keys(), $this->ownerHeaders)->assertOk()->assertJsonPath('data.collected_by', 'partner');
        // Chủ đối tác của đối tác KHÁC không đụng được vào kênh này.
        $other = Partner::create(['partner_type' => Partner::TYPE_HOMESTAY, 'name' => 'Đối tác khác', 'status' => true]);
        $this->postJson("/api/admin/partners/{$other->id}/payment-channel", $this->keys(), $this->ownerHeaders)->assertForbidden();

        $notification = $this->admin->notifications()->get()->first(fn ($n) => ($n->data['viewData']['type'] ?? null) === 'partner_payment_channel_changed');
        $this->assertNotNull($notification, 'Super Admin phải được báo khi đối tác tự đổi kênh.');
        $this->assertSame($this->partner->id, $notification->data['viewData']['partner_id']);

        // Super Admin tự đổi thì không tự báo cho chính mình.
        $this->postJson($this->url(), ['is_active' => false], $this->adminHeaders)->assertOk();
        $this->assertSame(1, $this->admin->notifications()->get()->filter(fn ($n) => ($n->data['viewData']['type'] ?? null) === 'partner_payment_channel_changed')->count());

        // Thu lại quyền: kênh đã lưu giữ nguyên, chủ đối tác không sửa được nữa.
        $this->putJson($this->url('/permission'), ['partner_setup_allowed' => false], $this->adminHeaders)->assertOk()->assertJsonPath('data.payos_configured', true);
        $this->postJson($this->url(), ['is_active' => true], $this->ownerHeaders)->assertForbidden();
    }

    public function test_wrong_keys_or_a_different_account_holder_are_rejected(): void
    {
        $this->fakePayOs(new \Exception('Kênh thanh toán không tồn tại'), 'TRAN THI BINH');
        $this->postJson($this->url(), $this->keys(), $this->adminHeaders)->assertStatus(422)->assertJsonValidationErrors('client_id');
        $this->assertDatabaseMissing('partner_payos_accounts', ['partner_id' => $this->partner->id]);

        $this->postJson($this->url(), $this->keys(), $this->adminHeaders)->assertStatus(422)->assertJsonValidationErrors('client_id');
        $this->assertDatabaseMissing('partner_payos_accounts', ['partner_id' => $this->partner->id]);

        // Thiếu khoá khi tạo mới, hoặc đối tác chưa có chủ tài khoản để đối chiếu.
        $this->postJson($this->url(), ['client_id' => 'only-client'], $this->adminHeaders)->assertStatus(422);
        $this->partner->update(['bank_account_holder' => null]);
        $this->postJson($this->url(), $this->keys(), $this->adminHeaders)->assertStatus(422)->assertJsonValidationErrors('client_id');
    }

    public function test_orders_use_the_partner_channel_unless_a_branch_overrides_it(): void
    {
        $order = new Order(['category_id' => $this->branch->id, 'partner_id' => $this->partner->id]);

        // Chưa có kênh riêng → tài khoản chung của 365home.
        $this->assertSame('platform', PayOsAccountResolver::forOrder($order)->collectedBy());
        $this->assertSame(['global-checksum'], PayOsAccountResolver::checksumKeysForOrder($order));

        $this->fakePayOs('NGUYEN VAN AN');
        $this->postJson($this->url(), $this->keys(), $this->adminHeaders)->assertOk();

        // Có kênh của đối tác → mọi chi nhánh (kể cả khu vực con) về tài khoản đối tác; webhook nhận key đối tác + key chung (link cũ).
        $area = Category::create(['name' => $name = 'Khu vuc con ' . uniqid(), 'slug' => Str::slug($name), 'category_type' => 'product', 'parent_id' => $this->branch->id, 'status' => true]);
        $gateway = PayOsAccountResolver::forOrder(new Order(['category_id' => $area->id]));
        $this->assertTrue($gateway->usesPartnerAccount());
        $this->assertSame('partner', $gateway->collectedBy());
        $this->assertSame(['partner-checksum', 'global-checksum'], PayOsAccountResolver::checksumKeysForOrder($order));
        $this->assertContains('partner-checksum', PayOsAccountResolver::allBranchChecksumKeys());

        // Đối tác khác không dùng được key của đối tác này.
        $other = Partner::create(['partner_type' => Partner::TYPE_HOMESTAY, 'name' => 'Đối tác khác', 'status' => true]);
        $otherBranch = Category::create(['name' => $name = 'Chi nhanh khac ' . uniqid(), 'slug' => Str::slug($name), 'category_type' => 'product', 'parent_id' => null, 'partner_id' => $other->id, 'status' => true]);
        $this->assertSame(['global-checksum'], PayOsAccountResolver::checksumKeysForOrder(new Order(['category_id' => $otherBranch->id])));

        // Chi nhánh có tài khoản ghi đè → theo chi nhánh.
        BranchPayOsAccount::create(['category_id' => $this->branch->id, 'is_active' => true, 'client_id' => 'branch-client', 'api_key' => 'branch-api', 'checksum_key' => 'branch-checksum']);
        $gateway = PayOsAccountResolver::forOrder($order);
        $this->assertTrue($gateway->usesBranchAccount());
        $this->assertFalse($gateway->usesPartnerAccount());

        // Tắt kênh đối tác + không còn ghi đè → quay về thu hộ, key cũ vẫn được nhận để xác nhận link đã tạo.
        BranchPayOsAccount::where('category_id', $this->branch->id)->delete();
        $this->postJson($this->url(), ['is_active' => false], $this->adminHeaders)->assertOk();
        $this->assertSame('platform', PayOsAccountResolver::forOrder($order)->collectedBy());
        $this->assertSame(['global-checksum', 'partner-checksum'], PayOsAccountResolver::checksumKeysForOrder($order));
    }
}

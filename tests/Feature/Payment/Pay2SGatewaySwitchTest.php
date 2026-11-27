<?php

namespace Tests\Feature\Payment;

use App\Models\User;
use App\Settings\Pay2SSettings;
use App\Support\Pay2SConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RestoresSettings;
use Tests\TestCase;

// Công tắc cổng PayOS ⇄ Pay2S và tài khoản ngân hàng của 365home: lưu qua API admin và trang CMS, khoá bí mật mã hoá và không bao giờ trả lại.
class Pay2SGatewaySwitchTest extends TestCase
{
    use DatabaseTransactions;
    use RestoresSettings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotSettings('pay2s');
        // Giá trị mã hoá có sẵn trong DB dev có thể không giải mã được bằng APP_KEY của máy này: đặt về rỗng (tearDown khôi phục nguyên trạng).
        DB::table('settings')->where('group', 'pay2s')->whereIn('name', ['api_token', 'partner_access_key', 'partner_secret_key', 'platform_webhook_token'])->update(['payload' => 'null']);
        app()->forgetInstance(Pay2SSettings::class);
        config(['pay2s.default_gateway' => 'payos', 'pay2s.enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->restoreSettings();
        app()->forgetInstance(Pay2SSettings::class);
        parent::tearDown();
    }

    private function admin(): array
    {
        $user = User::role('super_admin')->firstOrFail();

        return ['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    public function test_the_api_switches_the_gateway_saves_the_platform_account_and_never_returns_the_token(): void
    {
        $response = $this->putJson('/api/admin/payment-gateways/pay2s', [
            'default_gateway' => 'pay2s', 'platform_bank_code' => 'VCB', 'platform_bank_bin' => '970436',
            'platform_account_number' => '0123456789', 'platform_account_holder' => 'CONG TY 365HOME', 'platform_webhook_token' => 'secret-platform-token-xyz',
        ], $this->admin())->assertOk()
            ->assertJsonPath('data.default_gateway.value', 'pay2s')
            ->assertJsonPath('data.collecting_via_pay2s', true)
            ->assertJsonPath('data.platform_account.configured', true)
            ->assertJsonPath('data.platform_account.account_number', '0123456789')
            ->assertJsonPath('data.platform_account.webhook_token.configured', true);

        $this->assertStringNotContainsString('secret-platform-token-xyz', $response->getContent());
        $this->assertStringNotContainsString('secret-platform-token-xyz', (string) DB::table('settings')->where('group', 'pay2s')->where('name', 'platform_webhook_token')->value('payload'), 'token phải mã hoá trong DB');
        $this->assertTrue(Pay2SConfig::collectsViaPay2S());

        // Gửi lại không kèm token: giữ nguyên token cũ. Chuyển về PayOS: tắt thu qua Pay2S nhưng không mất tài khoản đã khai báo.
        $this->putJson('/api/admin/payment-gateways/pay2s', ['default_gateway' => 'payos'], $this->admin())->assertOk()
            ->assertJsonPath('data.default_gateway.value', 'payos')
            ->assertJsonPath('data.collecting_via_pay2s', false)
            ->assertJsonPath('data.platform_account.webhook_token.configured', true);
        $this->assertFalse(Pay2SConfig::collectsViaPay2S());
        $this->assertNotNull(Pay2SConfig::platformAccount(false));
    }

    public function test_an_unknown_gateway_value_is_rejected(): void
    {
        $this->putJson('/api/admin/payment-gateways/pay2s', ['default_gateway' => 'momo'], $this->admin())->assertStatus(422);
    }

    public function test_the_cms_form_normalisation_keeps_the_token_when_left_blank_and_drops_unknown_gateways(): void
    {
        $service = app(\App\Services\Payment\PaymentGatewaySettingsService::class);
        $service->update('pay2s', ['default_gateway' => 'pay2s', 'platform_bank_bin' => '970436', 'platform_account_number' => '0123456789', 'platform_webhook_token' => 'cms-platform-token-123']);
        app()->forgetInstance(Pay2SSettings::class);

        // Trang CMS gửi token để trống (không bao giờ hiện lại): phải giữ token cũ.
        $data = $service->normalize('pay2s', ['enabled' => true, 'default_gateway' => 'pay2s', 'platform_webhook_token' => null, 'platform_account_number' => ' 0123456789 '], app(Pay2SSettings::class));
        $this->assertSame('cms-platform-token-123', $data['platform_webhook_token']);
        $this->assertSame('0123456789', $data['platform_account_number']);
        $this->assertSame('pay2s', $data['default_gateway']);

        $bad = $service->normalize('pay2s', ['enabled' => true, 'default_gateway' => 'bogus'], app(Pay2SSettings::class));
        $this->assertNull($bad['default_gateway'], 'giá trị lạ = chưa chọn (theo PayOS)');
    }
}

<?php

namespace Tests\Feature\Payment;

use App\Filament\Pages\Setting\ManageSePaySettings;
use App\Models\Partner;
use App\Models\User;
use App\Services\Payment\SePayService;
use App\Settings\SePaySettings;
use App\Support\SePayConfig;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\RestoresSettings;
use Tests\TestCase;

// Cấu hình chung của SePay NHẬP Ở CMS (không cần .env): lưu mã hoá, ưu tiên hơn .env, để trống = giữ nguyên khoá, rơi về .env khi chưa nhập hoặc đọc lỗi,
// và các chức năng (xác thực webhook, bật/tắt SePay, tiền tố mã, token API) đọc đúng giá trị CMS.
class SePayCmsSettingsTest extends TestCase
{
    use DatabaseTransactions;
    use RestoresSettings;

    protected function tearDown(): void
    {
        $this->restoreSettings(); // cài đặt không được hoàn tác theo giao dịch
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotSettings('sepay');

        // Bắt đầu từ trạng thái "chưa nhập ở CMS" và theo .env giả lập bên dưới.
        $settings = app(SePaySettings::class);
        $settings->fill(['enabled' => null, 'webhook_secret' => null, 'code_prefix' => null, 'api_token' => null, 'min_amount' => null])->save();
        app()->forgetInstance(SePaySettings::class);

        config(['sepay.enabled' => true, 'sepay.webhook_secret' => 'env-secret', 'sepay.code_prefix' => 'DH', 'sepay.api_token' => null, 'sepay.min_amount' => 1000]);

        $this->actingAs(User::role('super_admin')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function fresh(): SePaySettings
    {
        app()->forgetInstance(SePaySettings::class);

        return app(SePaySettings::class);
    }

    public function test_super_admin_opens_the_page_and_secrets_are_never_shown(): void
    {
        $this->fresh()->fill(['webhook_secret' => 'cms-secret-123456', 'api_token' => 'token-abcdef'])->save();
        app()->forgetInstance(SePaySettings::class);

        Livewire::test(ManageSePaySettings::class)->assertSuccessful()
            ->assertFormSet(['enabled' => true, 'webhook_secret' => null, 'api_token' => null])
            ->assertDontSee('cms-secret-123456')->assertDontSee('token-abcdef');
    }

    public function test_saving_stores_encrypted_values_that_override_env(): void
    {
        Livewire::test(ManageSePaySettings::class)
            ->fillForm(['enabled' => true, 'webhook_secret' => 'my-new-webhook-secret-001', 'code_prefix' => 'hd', 'api_token' => 'api-token-from-cms', 'min_amount' => 1500])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('my-new-webhook-secret-001', SePayConfig::get('webhook_secret'), 'giá trị CMS thắng .env');
        $this->assertSame('HD', SePayConfig::get('code_prefix'), 'tiền tố được viết hoa');
        $this->assertSame('api-token-from-cms', SePayConfig::get('api_token'));
        $this->assertSame(1500, (int) SePayConfig::get('min_amount'));
        $this->assertSame('cms', SePayConfig::source('webhook_secret'));

        foreach (['webhook_secret', 'api_token'] as $name) {
            $raw = (string) DB::table('settings')->where('group', 'sepay')->where('name', $name)->value('payload');
            $this->assertStringNotContainsString('my-new-webhook-secret-001', $raw);
            $this->assertStringNotContainsString('api-token-from-cms', $raw, 'khoá bí mật phải được mã hoá trong DB');
        }
    }

    public function test_leaving_the_secret_fields_blank_keeps_the_existing_values(): void
    {
        Livewire::test(ManageSePaySettings::class)
            ->fillForm(['webhook_secret' => 'first-secret-value-0001', 'api_token' => 'first-token'])->call('save')->assertHasNoFormErrors();

        Livewire::test(ManageSePaySettings::class)
            ->fillForm(['webhook_secret' => '', 'api_token' => '', 'code_prefix' => 'DH'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('first-secret-value-0001', SePayConfig::get('webhook_secret'));
        $this->assertSame('first-token', SePayConfig::get('api_token'));
    }

    public function test_validation_rejects_a_short_secret_a_bad_prefix_and_a_bad_amount(): void
    {
        Livewire::test(ManageSePaySettings::class)
            ->fillForm(['webhook_secret' => 'short', 'code_prefix' => 'D', 'min_amount' => 0])->call('save')
            ->assertHasFormErrors(['webhook_secret', 'code_prefix', 'min_amount']);
        Livewire::test(ManageSePaySettings::class)
            ->fillForm(['code_prefix' => 'D H!'])->call('save')->assertHasFormErrors(['code_prefix']);
    }

    public function test_without_cms_values_everything_follows_env_and_a_settings_failure_does_not_break_it(): void
    {
        $this->assertSame('env-secret', SePayConfig::get('webhook_secret'));
        $this->assertSame('env', SePayConfig::source('webhook_secret'));
        $this->assertSame('none', SePayConfig::source('api_token'));
        $this->assertTrue(SePayConfig::enabled());

        $this->instance(SePaySettings::class, (new \ReflectionClass(SePaySettings::class))->newInstanceWithoutConstructor());
        $this->assertSame('env-secret', SePayConfig::get('webhook_secret'), 'cài đặt đọc lỗi → rơi về .env');
        $this->assertTrue(SePayConfig::enabled());
    }

    public function test_features_read_the_cms_values(): void
    {
        $sePay = app(SePayService::class);

        // Tiền tố
        $this->fresh()->fill(['code_prefix' => 'HD'])->save();
        $this->assertSame('HD', $sePay->prefix());
        $this->assertSame(12345678, $sePay->extractCode(['code' => 'HD12345678']));
        $this->assertNull($sePay->extractCode(['code' => 'DH12345678']));

        // Khoá bí mật webhook: ký bằng khoá CMS thì được nhận, ký bằng khoá .env thì không
        $this->fresh()->fill(['webhook_secret' => 'cms-webhook-secret-xyz'])->save();
        $body = json_encode(['id' => 0, 'accountNumber' => '1', 'transferType' => 'in', 'transferAmount' => 1]);
        $call = function (string $secret) use ($body) {
            $ts = now()->timestamp;

            return $this->call('POST', '/api/webhook/sepay', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SEPAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret), 'HTTP_X_SEPAY_TIMESTAMP' => (string) $ts,
            ], $body);
        };
        $call('cms-webhook-secret-xyz')->assertOk();
        $call('env-secret')->assertStatus(401);

        // Công tắc tổng ở CMS (kể cả khi .env bật) và số tiền tối thiểu
        $partner = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->firstOrFail();
        $this->fresh()->fill(['enabled' => false, 'min_amount' => 3000])->save();
        $this->assertFalse(SePayConfig::enabled());
        $this->assertFalse($sePay->canPull());

        $this->fresh()->fill(['enabled' => true, 'api_token' => 'tok'])->save();
        $this->assertTrue($sePay->canPull());
        $this->assertSame(3000, (int) SePayConfig::get('min_amount'));
        $this->assertNotNull($partner);
    }

    public function test_the_connection_test_button_reports_success_and_failure(): void
    {
        $this->fresh()->fill(['api_token' => 'tok-ok'])->save();
        app()->forgetInstance(SePaySettings::class);

        $status = 200;
        Http::fake(['my.sepay.vn/*' => function () use (&$status) {
            return Http::response(['status' => $status, 'transactions' => []], $status);
        }]);
        Livewire::test(ManageSePaySettings::class)->callAction('testApi')->assertNotified('Kết nối API SePay thành công.');

        $status = 401;
        $this->assertFalse(app(SePayService::class)->testApi()['ok']);
        $this->assertStringContainsString('từ chối', app(SePayService::class)->testApi()['message']);

        $this->fresh()->fill(['api_token' => null])->save();
        config(['sepay.api_token' => null]);
        $this->assertSame('Chưa nhập API Token SePay.', app(SePayService::class)->testApi()['message']);
    }

    public function test_only_super_admin_can_open_the_page(): void
    {
        $this->assertTrue(ManageSePaySettings::canAccess());

        $partnerUser = new User(['fullname' => 'Doi tac', 'email' => 'sepay-cfg-' . uniqid() . '@test.local', 'password' => 'secret-secret']);
        $partnerUser->forceFill(['partner_id' => Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->value('id')])->save();
        $this->actingAs($partnerUser);

        $this->assertFalse(ManageSePaySettings::canAccess());
    }
}

<?php

namespace Tests\Feature\Payment;

use App\Models\User;
use App\Settings\Pay2SSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\Concerns\RestoresSettings;
use Tests\TestCase;

// Trang CMS "Pay2S — cấu hình chung": chạy trên cài đặt giả lập (DB dev có thể chứa giá trị mã hoá bằng APP_KEY khác, không giải mã được).
class Pay2SSettingsPageTest extends TestCase
{
    use DatabaseTransactions;
    use RestoresSettings;

    protected function setUp(): void
    {
        parent::setUp();
        // Trang CMS lưu thẳng vào bảng settings (cài đặt không nằm trong giao dịch test): chụp lại để tearDown khôi phục, không rò sang test khác.
        $this->snapshotSettings('pay2s');
        Pay2SSettings::fake([
            'enabled' => null, 'api_token' => null, 'partner_access_key' => null, 'partner_secret_key' => null, 'partner_api_url' => null, 'partner_parent_origin' => null,
            'default_gateway' => null, 'platform_bank_code' => null, 'platform_bank_bin' => null, 'platform_account_number' => null, 'platform_account_holder' => null, 'platform_webhook_token' => null,
        ]);
        config(['pay2s.default_gateway' => 'payos', 'pay2s.enabled' => true]);
        $this->actingAs(User::role('super_admin')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        $this->restoreSettings();
        app()->forgetInstance(Pay2SSettings::class);
        parent::tearDown();
    }

    public function test_the_page_opens_on_payos_and_hides_secrets(): void
    {
        Livewire::test(\App\Filament\Pages\Setting\ManagePay2SSettings::class)->assertSuccessful()
            ->assertFormSet(['default_gateway' => 'payos', 'platform_webhook_token' => null, 'api_token' => null])
            ->assertSee('Cổng chuyển khoản mặc định');
    }

    public function test_choosing_pay2s_requires_the_platform_bank_account(): void
    {
        Livewire::test(\App\Filament\Pages\Setting\ManagePay2SSettings::class)
            ->fillForm(['enabled' => true, 'default_gateway' => 'pay2s'])
            ->call('save')
            ->assertHasFormErrors(['platform_bank_bin' => 'required', 'platform_account_number' => 'required', 'platform_account_holder' => 'required']);

        Livewire::test(\App\Filament\Pages\Setting\ManagePay2SSettings::class)
            ->fillForm(['enabled' => true, 'default_gateway' => 'payos'])
            ->call('save')->assertHasNoFormErrors();
    }

    public function test_saving_a_complete_account_switches_the_gateway_and_keeps_the_token_when_left_blank(): void
    {
        Livewire::test(\App\Filament\Pages\Setting\ManagePay2SSettings::class)
            ->fillForm(['enabled' => true, 'default_gateway' => 'pay2s', 'platform_bank_bin' => '970436', 'platform_bank_code' => 'VCB',
                'platform_account_number' => '0123456789', 'platform_account_holder' => 'CONG TY 365HOME', 'platform_webhook_token' => 'cms-platform-token-123'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('pay2s', \App\Support\Pay2SConfig::gateway());
        $this->assertSame('cms-platform-token-123', \App\Support\Pay2SConfig::platformAccount()->webhook_token);

        Livewire::test(\App\Filament\Pages\Setting\ManagePay2SSettings::class)
            ->assertFormSet(['default_gateway' => 'pay2s', 'platform_webhook_token' => null])
            ->fillForm(['platform_webhook_token' => null])->call('save')->assertHasNoFormErrors();

        $this->assertSame('cms-platform-token-123', \App\Support\Pay2SConfig::platformAccount()->webhook_token, 'để trống = giữ token cũ');
    }
}

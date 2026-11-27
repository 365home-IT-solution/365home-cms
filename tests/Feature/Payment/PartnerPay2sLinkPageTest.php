<?php

namespace Tests\Feature\Payment;

use App\Filament\Pages\PartnerPay2sLink;
use App\Models\Partner;
use App\Models\User;
use App\Settings\Pay2SSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Payment\Entities\PartnerPayOsAccount;
use Modules\Payment\Entities\PartnerPay2sLinkSession;
use Modules\Payment\Entities\PartnerSePayAccount;
use Tests\TestCase;

// Trang "Liên kết ngân hàng Pay2S" của chủ đối tác: tự mở Hosted Link, nhập OTP trên trang Pay2S, trang tự lưu tài khoản + webhook khi connected. Pay2S được giả lập (Http::fake).
class PartnerPay2sLinkPageTest extends TestCase
{
    use DatabaseTransactions;

    private Partner $partner;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Pay2SSettings::fake([
            'enabled' => null, 'api_token' => null, 'partner_access_key' => 'ak-test', 'partner_secret_key' => 'sk-test', 'partner_api_url' => 'https://api-partner.pay2s.test', 'partner_parent_origin' => 'https://365home.test',
            'default_gateway' => null, 'platform_bank_code' => null, 'platform_bank_bin' => null, 'platform_account_number' => null, 'platform_account_holder' => null, 'platform_webhook_token' => null,
        ]);
        config(['pay2s.enabled' => true]);

        $this->partner = Partner::create([
            'partner_type' => Partner::TYPE_HOMESTAY, 'name' => 'Homestay Liên Kết', 'legal_name' => 'Homestay Liên Kết', 'phone' => '0970000822',
            'status' => true, 'verification_status' => 'approved', 'contract_status' => 'active',
        ]);
        // Không nằm trong $fillable: bật quyền tự cấu hình như 365home làm ở trang Đối tác.
        $this->partner->forceFill(['payos_self_setup_enabled' => true])->save();
        $this->owner = User::create(['fullname' => 'Chủ đối tác', 'email' => 'p2s-owner-' . uniqid() . '@example.test', 'password' => 'secret-secret', 'partner_id' => $this->partner->id]);
        $this->owner->assignRole(config('permission.models.role')::firstOrCreate(['name' => 'partner', 'guard_name' => 'web']));

        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private string $sessionStatus = 'awaiting_otp';

    private bool $faked = false;

    private bool $bankNeedsOtp = true;

    private bool $bankDenied = false;

    /** Pay2S đã có tài khoản 0988776655 trong danh sách (đã thêm trước đó / sau khi xác nhận OTP). */
    private bool $bankListed = false;

    /** @var list<array<string,mixed>> */
    private array $existingWebhooks = [];

    /** Giả lập Pay2S; đổi $status giữa chừng (vd awaiting_otp → connected) mà không đăng ký lại (Http::fake lấy khai báo đầu tiên khớp). */
    private function fakePay2S(string $sessionStatus = 'awaiting_otp'): void
    {
        $this->sessionStatus = $sessionStatus;

        if ($this->faked) {
            return;
        }

        $this->faked = true;
        Http::fake([
            '*/v1/auth/authorize' => Http::response(['data' => ['access_token' => 'tok-1']]),
            '*/v1/bankhub/link-sessions' => Http::response(['data' => ['session_id' => 'sess-123', 'hosted_link_url' => 'https://hosted.pay2s.test/link/sess-123', 'expires_in' => 900]]),
            '*/v1/bankhub/link-sessions/sess-123' => fn () => Http::response(['data' => [
                'status' => $this->sessionStatus,
                'account' => ['account_number' => '0011223344', 'bank_code' => 'VCB', 'account_holder' => 'nguyen van chu', 'user_bank_id' => 'ub-9'],
            ]]),
            '*/v1/webhooks' => fn ($request) => $request->method() === 'POST'
                ? Http::response(['data' => ['webhook_token' => 'wh-token-abc']])
                : Http::response(['data' => $this->existingWebhooks]),
            '*/v1/banks/confirm-otp' => function ($request) {
                if ($request['otp'] === '123456') {
                    $this->bankListed = true;

                    return Http::response(['status' => true, 'message' => 'Hợp lệ']);
                }

                return Http::response(['status' => false, 'message' => 'Fail to verify OTP value']);
            },
            '*/v1/banks' => fn ($request) => $this->bankDenied
                ? Http::response(['status' => false, 'message' => 'Tài khoản không có quyền sử dụng BankHub.'], 403)
                : ($request->method() === 'POST'
                ? (function () {
                    if (! $this->bankNeedsOtp) {
                        $this->bankListed = true;
                    }

                    return Http::response(['status' => true, 'message' => 'Đã gửi OTP', 'OTP' => $this->bankNeedsOtp ? 1 : 0]);
                })()
                : Http::response(['status' => true, 'message' => ! $this->bankListed ? [] : [['id' => 77, 'accountNumber' => '0988776655', 'vaNumber' => 'VA0988776655', 'status' => 1, 'statusText' => 'Đang hoạt động', 'bankName' => 'MBBank']]])),
        ]);
    }

    public function test_only_partner_owners_with_the_role_can_open_the_page(): void
    {
        $this->assertTrue(PartnerPay2sLink::canAccess());

        $this->actingAs(User::role('super_admin')->firstOrFail());
        $this->assertFalse(PartnerPay2sLink::canAccess(), 'Super Admin dùng trang "SePay / Pay2S theo đối tác"');

        $plain = User::create(['fullname' => 'Nhân viên', 'email' => 'p2s-staff-' . uniqid() . '@example.test', 'password' => 'secret-secret', 'partner_id' => $this->partner->id]);
        $this->actingAs($plain);
        $this->assertFalse(PartnerPay2sLink::canAccess());
    }

    public function test_the_page_works_without_any_permission_granted_by_super_admin(): void
    {
        $this->fakePay2S();
        $this->partner->forceFill(['payos_self_setup_enabled' => false])->save();

        Livewire::test(PartnerPay2sLink::class)->assertSuccessful()->assertSee('Cách nhận tiền của từng chi nhánh')->assertSee('Liên kết ngân hàng bằng OTP')->assertDontSee('chưa bật quyền');
    }

    public function test_it_refuses_to_start_while_the_partner_payos_channel_is_active(): void
    {
        $this->fakePay2S();
        PartnerPayOsAccount::query()->create(['partner_id' => $this->partner->id, 'client_id' => 'c', 'api_key' => 'k', 'checksum_key' => 's', 'is_active' => true]);

        Livewire::test(PartnerPay2sLink::class)->call('startLink')->assertSet('sessionId', null)->assertSee('kênh PayOS riêng');

        $this->assertSame(0, PartnerPay2sLinkSession::query()->where('partner_id', $this->partner->id)->count());
    }

    public function test_the_owner_links_a_bank_and_the_account_and_webhook_are_saved_when_connected(): void
    {
        $this->fakePay2S('awaiting_otp');

        $page = Livewire::test(PartnerPay2sLink::class)->call('startLink')
            ->assertSet('sessionId', 'sess-123')
            ->assertDispatched('pay2s-open', url: 'https://hosted.pay2s.test/link/sess-123', sessionId: 'sess-123')
            ->assertSee('Mở lại cửa sổ liên kết')
            ->assertSee('pay2s-bankhub-1.1.0.js')
            ->assertSee('Mở trong tab mới')
            ->assertSee('https://hosted.pay2s.test/link/sess-123');

        $session = PartnerPay2sLinkSession::query()->where('session_id', 'sess-123')->firstOrFail();
        $this->assertSame($this->partner->id, $session->partner_id);
        $this->assertFalse($session->applied);
        $this->assertNull(PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->first());

        // Khách nhập OTP trên trang Pay2S xong → phiên connected: trang tự lưu tài khoản và token webhook.
        $this->fakePay2S('connected');
        $page->call('refreshSession')->assertSee('Đã liên kết ngân hàng thành công');

        $account = PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->firstOrFail();
        $this->assertSame('pay2s', $account->provider);
        $this->assertSame('0011223344', $account->account_number);
        $this->assertSame('NGUYEN VAN CHU', $account->account_holder);
        $this->assertTrue($account->is_active);
        $this->assertSame('wh-token-abc', $account->webhook_token);
        $this->assertTrue($session->fresh()->applied);

        $page->call('check')->assertSee('Có token webhook Pay2S');
    }

    public function test_a_failed_session_shows_the_reason_and_saves_nothing(): void
    {
        $this->fakePay2S('failed');
        $page = Livewire::test(PartnerPay2sLink::class)->call('startLink')->call('refreshSession')->assertSee('Liên kết chưa thành công');

        $this->assertNull(PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->first());
        $page->assertDontSee('Mở trong tab mới');
    }

    public function test_a_branch_outside_the_partner_cannot_be_targeted(): void
    {
        $this->fakePay2S();

        Livewire::test(PartnerPay2sLink::class)->set('categoryId', 999999999)->call('startLink')->assertSet('sessionId', null);

        $this->assertSame(0, PartnerPay2sLinkSession::query()->where('partner_id', $this->partner->id)->count());
    }

    public function test_the_empty_scope_from_the_select_means_the_default_account(): void
    {
        $this->fakePay2S();

        Livewire::test(PartnerPay2sLink::class)->set('categoryId', '')->call('startLink')->assertSet('sessionId', 'sess-123');

        $this->assertNull(PartnerPay2sLinkSession::query()->where('session_id', 'sess-123')->value('category_id'));
    }

    public function test_the_admin_api_start_uses_the_same_rules_and_still_works(): void
    {
        $this->fakePay2S();
        $headers = ['Authorization' => 'Bearer ' . $this->owner->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

        $this->postJson("/api/admin/partners/{$this->partner->id}/pay2s-link", [], $headers)->assertCreated()
            ->assertJsonPath('data.session_id', 'sess-123')->assertJsonPath('data.hosted_link_url', 'https://hosted.pay2s.test/link/sess-123');

        $this->app['auth']->forgetGuards();
        PartnerPayOsAccount::query()->create(['partner_id' => $this->partner->id, 'client_id' => 'c', 'api_key' => 'k', 'checksum_key' => 's', 'is_active' => true]);
        $this->postJson("/api/admin/partners/{$this->partner->id}/pay2s-link", [], $headers)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'kênh PayOS riêng'));
    }

    private function fillBank(array $override = []): array
    {
        return $override + ['bank' => 'MB', 'account_type' => 'personal', 'account_number' => '0988776655', 'account_holder' => 'Nguyen Van Chu', 'phone' => '0900000001', 'email' => 'chu@example.test', 'cccd' => '012345678901', 'merchant_id' => '', 'username' => ''];
    }

    public function test_the_owner_links_an_openapi_bank_by_entering_the_otp_in_the_page(): void
    {
        $this->fakePay2S();

        $page = Livewire::test(PartnerPay2sLink::class)->set('bank', $this->fillBank())->call('submitBank')->assertHasNoErrors()
            ->assertSet('awaitingOtp', true)->assertSee('Xác nhận OTP')->assertSee('0900000001');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/banks') && $r->method() === 'POST'
            && $r['type'] === 'openapi' && $r['bankShortName'] === 'MBB' && $r['accountNumber'] === '0988776655' && $r['accName'] === 'NGUYEN VAN CHU' && $r['cccd'] === '012345678901' && $r['accMobile'] === '0900000001');
        $this->assertNull(PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->first());

        // OTP sai: chưa liên kết, vẫn ở bước nhập OTP.
        $page->set('otp', '000000')->call('confirmOtp')->assertSet('awaitingOtp', true)->assertSet('otp', '');
        $this->assertNull(PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->first());

        // OTP đúng: lưu tài khoản (số tài khoản ảo nếu Pay2S trả về) + token webhook.
        $page->set('otp', '123456')->call('confirmOtp')->assertSet('awaitingOtp', false)->assertSee('VA0988776655');

        $account = PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->firstOrFail();
        $this->assertSame('pay2s', $account->provider);
        $this->assertSame('VA0988776655', $account->account_number, 'khách phải chuyển vào số tài khoản ảo');
        $this->assertSame('MB', $account->bank_code);
        $this->assertSame('NGUYEN VAN CHU', $account->account_holder);
        $this->assertSame('wh-token-abc', $account->webhook_token);
        $this->assertTrue($account->is_active);
        $this->assertTrue(\App\Models\PartnerStatusLog::query()->where('partner_id', $this->partner->id)->where('note', 'like', 'Tự liên kết ngân hàng Pay2S (OTP)%')->exists());
    }

    public function test_a_bank_without_an_otp_step_is_finalised_straight_away_and_required_fields_are_validated(): void
    {
        $this->fakePay2S();
        $this->bankNeedsOtp = false;

        Livewire::test(PartnerPay2sLink::class)->set('bank', $this->fillBank(['cccd' => '', 'account_number' => '12', 'email' => 'x']))->call('submitBank')
            ->assertHasErrors(['bank.cccd', 'bank.account_number', 'bank.email'])->assertSet('awaitingOtp', false);
        $this->assertNull(PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->first());

        Livewire::test(PartnerPay2sLink::class)->set('bank', $this->fillBank())->call('submitBank')->assertHasNoErrors()->assertSet('awaitingOtp', false);
        $this->assertNotNull(PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->first());
    }

    public function test_manual_mode_needs_only_basic_bank_info_and_the_partner_confirms_payments_itself(): void
    {
        $page = Livewire::test(PartnerPay2sLink::class)->call('setMode', 'manual')
            ->assertSee('Thông tin ngân hàng nhận tiền (thủ công)')->assertDontSee('Gửi thông tin và nhận OTP')->assertDontSee('Token webhook');

        $page->set('manual', ['bank_code' => 'VCB', 'account_number' => '0011223399', 'account_holder' => 'Nguyen Van Chu'])->call('saveManual')->assertHasNoErrors();

        $account = PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->firstOrFail();
        $this->assertSame('manual', $account->provider);
        $this->assertTrue($account->isManual());
        $this->assertSame('970436', $account->bank_bin);
        $this->assertSame('NGUYEN VAN CHU', $account->account_holder);
        $this->assertNull($account->webhook_token);
        $this->assertTrue($account->is_active);
        $this->assertSame(1, PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->count());
        $this->assertTrue(\App\Models\PartnerStatusLog::query()->where('partner_id', $this->partner->id)->where('note', 'like', 'Chọn nhận tiền THỦ CÔNG%')->exists());
    }

    public function test_manual_entry_rejects_bad_input_taken_numbers_and_active_payos_channels(): void
    {
        $other = Partner::create(['partner_type' => Partner::TYPE_HOMESTAY, 'name' => 'Doi tac khac', 'legal_name' => 'Doi tac khac', 'phone' => '0970000833', 'status' => true, 'verification_status' => 'approved', 'contract_status' => 'active']);
        PartnerSePayAccount::query()->create(['partner_id' => $other->id, 'provider' => 'pay2s', 'is_active' => true, 'bank_code' => 'ACB', 'bank_bin' => '970416', 'account_number' => '5566778899', 'account_holder' => 'KHAC']);

        $page = Livewire::test(PartnerPay2sLink::class)->call('setMode', 'manual')
            ->set('manual', ['bank_code' => 'NOPE', 'account_number' => 'a b', 'account_holder' => ''])->call('saveManual')
            ->assertHasErrors(['manual.bank_code', 'manual.account_number', 'manual.account_holder']);

        $page->set('manual', ['bank_code' => 'VCB', 'account_number' => '5566778899', 'account_holder' => 'Chu'])->call('saveManual')->assertHasErrors(['manual.account_number']);
        $this->assertNull(PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->first());

        PartnerPayOsAccount::query()->create(['partner_id' => $this->partner->id, 'client_id' => 'c', 'api_key' => 'k', 'checksum_key' => 's', 'is_active' => true]);
        $page->set('manual', ['bank_code' => 'VCB', 'account_number' => '0011223344', 'account_holder' => 'Chu'])->call('saveManual');
        $this->assertNull(PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->first(), 'kênh PayOS riêng đang bật thì không lưu');
    }

    private function branch(string $name): int
    {
        return \Modules\Category\Entities\Category::withoutGlobalScopes()->forceCreate([
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) . '-' . \Illuminate\Support\Str::random(5), 'category_type' => 'product', 'status' => 1, 'partner_id' => $this->partner->id,
        ])->id;
    }

    public function test_each_branch_picks_auto_or_manual_independently(): void
    {
        $this->fakePay2S();
        $a = $this->branch('Chi nhanh A');
        $b = $this->branch('Chi nhanh B');

        // Chi nhánh A: TỰ ĐỘNG bằng OTP. Chi nhánh B: THỦ CÔNG. Cả đối tác (mặc định) để trống.
        $page = Livewire::test(PartnerPay2sLink::class)->call('selectScope', (string) $a)->assertSet('mode', 'auto')
            ->set('bank', $this->fillBank())->call('submitBank')->assertSet('awaitingOtp', true)
            ->set('otp', '123456')->call('confirmOtp')->assertSet('awaitingOtp', false);

        $page->call('selectScope', (string) $b)->assertSet('mode', 'auto')->call('setMode', 'manual')
            ->set('manual', ['bank_code' => 'ACB', 'account_number' => '9988776655', 'account_holder' => 'Chu B'])->call('saveManual')->assertHasNoErrors();

        $accA = PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->where('category_id', $a)->firstOrFail();
        $accB = PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->where('category_id', $b)->firstOrFail();
        $this->assertSame('pay2s', $accA->provider);
        $this->assertSame('VA0988776655', $accA->account_number);
        $this->assertSame('manual', $accB->provider);
        $this->assertSame('9988776655', $accB->account_number);
        $this->assertNull(PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->whereNull('category_id')->first());

        // Tổng quan hiện đúng cách nhận tiền từng chi nhánh; mở lại một chi nhánh thì đúng chế độ của nó.
        $overview = collect(Livewire::test(PartnerPay2sLink::class)->instance()->overview())->keyBy('id');
        $this->assertSame('auto', $overview[(string) $a]['mode']);
        $this->assertSame('manual', $overview[(string) $b]['mode']);
        $this->assertSame('none', $overview['']['mode']);

        Livewire::test(PartnerPay2sLink::class)->call('selectScope', (string) $b)->assertSet('mode', 'manual')->assertSet('manual.account_number', '9988776655')
            ->call('selectScope', (string) $a)->assertSet('mode', 'auto')
            ->call('selectScope', '')->assertSet('mode', 'auto');
    }

    public function test_switching_a_branch_from_manual_to_auto_replaces_the_account_and_a_foreign_branch_is_refused(): void
    {
        $this->fakePay2S();
        $a = $this->branch('Chi nhanh C');
        PartnerSePayAccount::query()->create(['partner_id' => $this->partner->id, 'category_id' => $a, 'provider' => 'manual', 'is_active' => true, 'bank_code' => 'ACB', 'bank_bin' => '970416', 'account_number' => '9900112233', 'account_holder' => 'CU']);

        Livewire::test(PartnerPay2sLink::class)->call('selectScope', (string) $a)->assertSet('mode', 'manual')
            ->call('setMode', 'auto')->set('bank', $this->fillBank())->call('submitBank')->set('otp', '123456')->call('confirmOtp');

        $account = PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->where('category_id', $a)->firstOrFail();
        $this->assertSame('pay2s', $account->provider);
        $this->assertSame(1, PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->where('category_id', $a)->count());

        // Chi nhánh của đối tác khác không thao tác được.
        Livewire::test(PartnerPay2sLink::class)->set('categoryId', 999999999)->call('setMode', 'manual')
            ->set('manual', ['bank_code' => 'VCB', 'account_number' => '0011223355', 'account_holder' => 'Chu'])->call('saveManual');
        $this->assertNull(PartnerSePayAccount::query()->where('account_number', '0011223355')->first());
    }

    public function test_an_automatic_account_without_a_webhook_token_lets_the_partner_paste_it(): void
    {
        PartnerSePayAccount::query()->create(['partner_id' => $this->partner->id, 'provider' => 'pay2s', 'is_active' => true, 'bank_code' => 'MB', 'bank_bin' => '970422', 'account_number' => '0988776655', 'account_holder' => 'CHU']);

        Livewire::test(PartnerPay2sLink::class)->assertSet('mode', 'auto')->assertSee('Token webhook Pay2S')
            ->set('webhookToken', 'pasted-token-123')->call('saveWebhookToken')->assertHasNoErrors();

        $this->assertSame('pasted-token-123', PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->first()->webhook_token);
    }

    public function test_the_partner_owner_can_use_the_account_api_without_the_payos_permission(): void
    {
        $this->partner->forceFill(['payos_self_setup_enabled' => false])->save();
        $headers = ['Authorization' => 'Bearer ' . $this->owner->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

        $this->postJson("/api/admin/partners/{$this->partner->id}/sepay-account", [
            'provider' => 'pay2s', 'bank_code' => 'VCB', 'account_number' => '0011223355', 'account_holder' => 'Chu', 'webhook_token' => 'api-token-1',
        ], $headers)->assertOk();

        $this->assertSame('0011223355', PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->value('account_number'));
    }

    public function test_when_pay2s_has_not_enabled_the_direct_otp_api_the_page_falls_back_to_the_pay2s_window(): void
    {
        $this->fakePay2S();
        $this->bankDenied = true;

        Livewire::test(PartnerPay2sLink::class)->set('bank', $this->fillBank())->call('submitBank')
            ->assertSet('awaitingOtp', false)->assertSet('sessionId', 'sess-123')
            ->assertDispatched('pay2s-open', url: 'https://hosted.pay2s.test/link/sess-123', sessionId: 'sess-123');

        $this->assertNull(PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->first());
    }

    public function test_the_otp_form_is_hidden_when_pay2s_has_not_enabled_the_direct_api_and_shown_when_it_has(): void
    {
        $this->fakePay2S();
        $this->bankDenied = true;

        Livewire::test(PartnerPay2sLink::class)->assertSuccessful()
            ->assertDontSee('Gửi thông tin và nhận OTP')->assertDontSee('Liên kết ngân hàng bằng OTP')
            ->assertSee('Liên kết qua cửa sổ Pay2S (ACB, MB Bank, BIDV');

        // Pay2S bật quyền: hết hạn nhớ 10 phút thì form OTP trở lại.
        Cache::flush();
        $this->bankDenied = false;

        Livewire::test(PartnerPay2sLink::class)->assertSee('Gửi thông tin và nhận OTP')->assertSee('Liên kết ngân hàng bằng OTP');
    }

    public function test_a_permission_error_on_submit_remembers_that_the_direct_api_is_unavailable(): void
    {
        $this->fakePay2S();

        // Lúc mở trang chưa biết (GET ổn), bấm gửi mới bị từ chối quyền → trang chuyển sang cửa sổ Pay2S và từ nay ẩn form.
        $page = Livewire::test(PartnerPay2sLink::class)->assertSee('Gửi thông tin và nhận OTP');
        $this->bankDenied = true;
        $page->set('bank', $this->fillBank())->call('submitBank')->assertDispatched('pay2s-open')
            ->assertDontSee('Gửi thông tin và nhận OTP');
    }

    public function test_the_owner_gets_the_webhook_token_automatically_from_pay2s(): void
    {
        $this->fakePay2S();
        $this->bankListed = true; // tài khoản đã có trong danh sách của Pay2S
        PartnerSePayAccount::query()->create(['partner_id' => $this->partner->id, 'provider' => 'pay2s', 'is_active' => true, 'bank_code' => 'MB', 'bank_bin' => '970422', 'account_number' => '0988776655', 'account_holder' => 'CHU']);

        Livewire::test(PartnerPay2sLink::class)->assertSee('Lấy token tự động')->call('provisionWebhook');

        $this->assertSame('wh-token-abc', PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->first()->webhook_token);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/webhooks') && $r->method() === 'POST' && $r['user_bank_id'] === '77' && $r['direction'] === 'IN' && str_ends_with($r['webhook_url'], '/api/webhook/pay2s'));
        $this->assertTrue(\App\Models\PartnerStatusLog::query()->where('partner_id', $this->partner->id)->where('note', 'like', 'Tạo webhook nhận tiền Pay2S tự động%')->exists());
    }

    public function test_an_existing_webhook_is_never_duplicated_and_an_unknown_account_is_reported(): void
    {
        $this->fakePay2S();
        $this->bankListed = true; // tài khoản đã có trong danh sách của Pay2S
        $url = route('webhook.pay2s');
        $this->existingWebhooks = [['id' => 5, 'user_bank_id' => 77, 'webhook_url' => $url]];
        $account = PartnerSePayAccount::query()->create(['partner_id' => $this->partner->id, 'provider' => 'pay2s', 'is_active' => true, 'bank_code' => 'MB', 'bank_bin' => '970422', 'account_number' => '0988776655', 'account_holder' => 'CHU']);

        // Đã có webhook nhưng hệ thống không giữ token: không tạo trùng, báo rõ.
        $result = app(\App\Services\Payment\Pay2SBankHubService::class)->provisionWebhook($account);
        $this->assertFalse($result['ok']);
        $this->assertFalse($result['created']);
        $this->assertStringContainsString('không lấy lại được', $result['message']);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/v1/webhooks') && $r->method() === 'POST');

        // Số tài khoản chưa liên kết ở Pay2S.
        $other = PartnerSePayAccount::query()->create(['partner_id' => Partner::create(['partner_type' => Partner::TYPE_HOMESTAY, 'name' => 'DT', 'legal_name' => 'DT', 'phone' => '0970000844', 'status' => true, 'verification_status' => 'approved', 'contract_status' => 'active'])->id,
            'provider' => 'pay2s', 'is_active' => true, 'bank_code' => 'MB', 'bank_bin' => '970422', 'account_number' => '1112223334', 'account_holder' => 'X']);
        $this->assertStringContainsString('Không thấy số tài khoản', app(\App\Services\Payment\Pay2SBankHubService::class)->provisionWebhook($other)['message']);
    }

    public function test_the_api_endpoint_provisions_the_webhook_for_the_partner_owner(): void
    {
        $this->fakePay2S();
        $this->bankListed = true; // tài khoản đã có trong danh sách của Pay2S
        PartnerSePayAccount::query()->create(['partner_id' => $this->partner->id, 'provider' => 'pay2s', 'is_active' => true, 'bank_code' => 'MB', 'bank_bin' => '970422', 'account_number' => '0988776655', 'account_holder' => 'CHU']);
        $headers = ['Authorization' => 'Bearer ' . $this->owner->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

        $this->postJson("/api/admin/partners/{$this->partner->id}/pay2s-webhook", [], $headers)->assertOk()->assertJsonPath('data.created', true)->assertJsonPath('data.has_webhook_token', true);
    }

    public function test_saving_a_pay2s_account_without_a_token_registers_the_webhook_immediately(): void
    {
        $this->fakePay2S();
        $this->bankListed = true; // tài khoản đã có trong danh sách của Pay2S
        $headers = ['Authorization' => 'Bearer ' . $this->owner->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

        // Đối tác lưu tài khoản (đã liên kết ở Pay2S) KHÔNG kèm token: hệ thống tự đăng ký webhook ngay.
        $this->postJson("/api/admin/partners/{$this->partner->id}/sepay-account", [
            'provider' => 'pay2s', 'bank_code' => 'MB', 'account_number' => '0988776655', 'account_holder' => 'Chu',
        ], $headers)->assertOk()->assertJsonPath('data.has_webhook_token', true);

        $this->assertSame('wh-token-abc', PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->first()->webhook_token);

        // Tài khoản thủ công không đăng ký webhook.
        $this->app['auth']->forgetGuards();
        $branch = $this->branch('CN Thu cong');
        $this->postJson("/api/admin/partners/{$this->partner->id}/sepay-account?category_id={$branch}", [
            'provider' => 'manual', 'bank_code' => 'VCB', 'account_number' => '0011223366', 'account_holder' => 'Chu', 'category_id' => $branch,
        ], $headers)->assertOk();
        $this->assertNull(PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->where('category_id', $branch)->first()->webhook_token);
    }

    public function test_a_number_taken_elsewhere_keeps_the_session_connected_and_can_be_completed_after_the_old_row_is_removed(): void
    {
        $this->fakePay2S('connected');
        $other = Partner::create(['partner_type' => Partner::TYPE_HOMESTAY, 'name' => 'Doi tac cu', 'legal_name' => 'Doi tac cu', 'phone' => '0970000855', 'status' => true, 'verification_status' => 'approved', 'contract_status' => 'active']);
        $old = PartnerSePayAccount::query()->create(['partner_id' => $other->id, 'provider' => 'pay2s', 'is_active' => true, 'bank_code' => 'MB', 'bank_bin' => '970422', 'account_number' => '0011223344', 'account_holder' => 'CU']);

        $page = Livewire::test(PartnerPay2sLink::class)->call('startLink')->call('refreshSession')
            ->assertSee('đã gắn cho một đối tác hoặc chi nhánh khác')->assertSee('Hoàn tất lưu tài khoản');

        $session = PartnerPay2sLinkSession::query()->where('session_id', 'sess-123')->firstOrFail();
        $this->assertSame('connected', $session->status);
        $this->assertFalse($session->applied);
        $this->assertSame('ACCOUNT_TAKEN', $session->error_code);
        $this->assertNull(PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->first());

        // Gỡ dòng cũ rồi hoàn tất: lưu tài khoản + tạo webhook, không phải liên kết lại.
        $old->delete();
        $page->call('refreshSession')->assertSee('Đã liên kết ngân hàng thành công');
        $this->assertTrue($session->fresh()->applied);
        $this->assertSame('0011223344', PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->value('account_number'));
    }

    public function test_an_account_already_added_at_pay2s_is_saved_without_asking_for_an_otp(): void
    {
        $this->fakePay2S();
        $this->bankListed = true; // đã thêm ở my.pay2s.vn từ trước

        Livewire::test(PartnerPay2sLink::class)->set('bank', $this->fillBank())->call('submitBank')->assertSet('awaitingOtp', false);

        $account = PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->firstOrFail();
        $this->assertSame('VA0988776655', $account->account_number);
        $this->assertSame('wh-token-abc', $account->webhook_token);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/v1/banks') && $r->method() === 'POST');
    }

    public function test_a_wrong_otp_keeps_the_otp_step_and_the_already_exists_messages_are_recognised(): void
    {
        $this->fakePay2S();

        $page = Livewire::test(PartnerPay2sLink::class)->set('bank', $this->fillBank())->call('submitBank')->assertSet('awaitingOtp', true);
        $page->set('otp', '000000')->call('confirmOtp')->assertSet('awaitingOtp', true);
        $this->assertNull(PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->first());

        $this->assertTrue(\App\Services\Payment\Pay2SBankHubService::isAlreadyExistsMessage('Record existed'));
        $this->assertTrue(\App\Services\Payment\Pay2SBankHubService::isAlreadyExistsMessage('Thông tin ngân hàng đã tồn tại.'));
        $this->assertFalse(\App\Services\Payment\Pay2SBankHubService::isAlreadyExistsMessage('Fail to verify OTP value'));
    }

    public function test_the_two_automatic_methods_are_tabs_and_only_the_selected_one_is_shown(): void
    {
        $this->fakePay2S();

        Livewire::test(PartnerPay2sLink::class)->assertSet('linkTab', 'otp')
            ->assertSee('Nhập thông tin + OTP (ACB, MB Bank, BIDV)')->assertSee('Gửi thông tin và nhận OTP')->assertDontSee('Liên kết qua cửa sổ Pay2S (ACB')
            ->call('setLinkTab', 'window')->assertSet('linkTab', 'window')
            ->assertSee('Liên kết qua cửa sổ Pay2S (ACB')->assertDontSee('Gửi thông tin và nhận OTP')
            ->call('setLinkTab', 'bogus')->assertSet('linkTab', 'window');

        // Pay2S chưa bật API trực tiếp: không còn tab, chỉ cửa sổ Pay2S.
        $this->bankDenied = true;
        Cache::flush();
        Livewire::test(PartnerPay2sLink::class)->assertDontSee('Nhập thông tin + OTP (ACB, MB Bank, BIDV)')->assertSee('Liên kết qua cửa sổ Pay2S (ACB');
    }

    // ───────────── API app: đối tác tự nhập OTP (banks → bank → bank/confirm-otp) ─────────────

    private function apiHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->owner->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    public function test_api_lists_otp_banks_and_whether_the_direct_api_is_enabled(): void
    {
        $this->fakePay2S();

        $this->getJson("/api/admin/partners/{$this->partner->id}/pay2s-link/banks", $this->apiHeaders())->assertOk()
            ->assertJsonPath('data.available', true)->assertJsonPath('data.direct_api_available', true)
            ->assertJsonPath('data.banks.0.code', 'ACB')->assertJsonPath('data.banks.1.requires_cccd', true);

        $this->bankDenied = true;
        Cache::flush();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/admin/partners/{$this->partner->id}/pay2s-link/banks", $this->apiHeaders())->assertOk()->assertJsonPath('data.direct_api_available', false);
    }

    public function test_api_owner_links_a_bank_in_two_steps_with_otp(): void
    {
        $this->fakePay2S();
        $url = "/api/admin/partners/{$this->partner->id}/pay2s-link";
        $headers = $this->apiHeaders();
        $body = $this->fillBank();

        // Bước 1: ngân hàng gửi OTP, chưa lưu gì.
        $this->postJson("$url/bank", $body, $headers)->assertOk()->assertJsonPath('data.state', 'otp_required')->assertJsonPath('data.account', null);
        $this->assertNull(PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->first());

        // OTP sai: 422, vẫn chưa lưu.
        $this->app['auth']->forgetGuards();
        $this->postJson("$url/bank/confirm-otp", $body + ['otp' => '000000'], $headers)->assertStatus(422)->assertJsonPath('data.state', 'otp_invalid');
        $this->assertNull(PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->first());

        // OTP đúng: lưu tài khoản ảo + token webhook + ghi lịch sử.
        $this->app['auth']->forgetGuards();
        $this->postJson("$url/bank/confirm-otp", $body + ['otp' => '123456'], $headers)->assertOk()
            ->assertJsonPath('data.state', 'connected')->assertJsonPath('data.account.account_number', 'VA0988776655')->assertJsonPath('data.account.has_webhook_token', true);

        $account = PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->firstOrFail();
        $this->assertSame('pay2s', $account->provider);
        $this->assertSame('wh-token-abc', $account->webhook_token);
        $this->assertTrue(\App\Models\PartnerStatusLog::query()->where('partner_id', $this->partner->id)->where('changed_by', $this->owner->id)->where('note', 'like', 'Tự liên kết ngân hàng Pay2S (OTP)%')->exists());
    }

    public function test_api_validates_input_and_blocks_foreign_partner_branch_and_payos_channel(): void
    {
        $this->fakePay2S();
        $url = "/api/admin/partners/{$this->partner->id}/pay2s-link";
        $headers = $this->apiHeaders();

        $this->postJson("$url/bank", $this->fillBank(['cccd' => '', 'account_number' => '12', 'email' => 'x']), $headers)->assertStatus(422)->assertJsonValidationErrors(['cccd', 'account_number', 'email']);
        $this->app['auth']->forgetGuards();
        $this->postJson("$url/bank/confirm-otp", $this->fillBank(), $headers)->assertStatus(422)->assertJsonValidationErrors(['otp']);
        $this->app['auth']->forgetGuards();
        $this->postJson("$url/bank", $this->fillBank(['category_id' => 999999]), $headers)->assertStatus(422)->assertJsonValidationErrors(['category_id']);

        // Chủ đối tác khác không được thao tác.
        $other = Partner::create(['partner_type' => Partner::TYPE_HOMESTAY, 'name' => 'Khác', 'legal_name' => 'Khác', 'phone' => '0970000823', 'status' => true, 'verification_status' => 'approved', 'contract_status' => 'active']);
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/admin/partners/{$other->id}/pay2s-link/bank", $this->fillBank(), $headers)->assertForbidden();

        // Đang dùng kênh PayOS riêng: không cho liên kết.
        PartnerPayOsAccount::query()->create(['partner_id' => $this->partner->id, 'client_id' => 'c', 'api_key' => 'k', 'checksum_key' => 's', 'is_active' => true]);
        $this->app['auth']->forgetGuards();
        $this->postJson("$url/bank", $this->fillBank(), $headers)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'kênh PayOS riêng'));
    }

    public function test_api_falls_back_to_a_hosted_link_session_when_pay2s_has_not_enabled_direct_otp(): void
    {
        $this->fakePay2S();
        $this->bankDenied = true;

        $this->postJson("/api/admin/partners/{$this->partner->id}/pay2s-link/bank", $this->fillBank(), $this->apiHeaders())->assertOk()
            ->assertJsonPath('data.state', 'hosted_link')->assertJsonPath('data.session.session_id', 'sess-123')->assertJsonPath('data.session.hosted_link_url', 'https://hosted.pay2s.test/link/sess-123');
    }

    public function test_api_saves_straight_away_when_the_account_is_already_at_pay2s(): void
    {
        $this->fakePay2S();
        $this->bankListed = true;

        $this->postJson("/api/admin/partners/{$this->partner->id}/pay2s-link/bank", $this->fillBank(), $this->apiHeaders())->assertOk()
            ->assertJsonPath('data.state', 'connected')->assertJsonPath('data.account.configured', true);
    }
}

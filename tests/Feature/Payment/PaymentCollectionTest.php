<?php

namespace Tests\Feature\Payment;

use App\Models\User;
use App\Settings\Pay2SSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Payment\Entities\PaymentCollection;
use Modules\Payment\Entities\SePayTransaction;
use Tests\TestCase;

// "Mã thu tiền": admin tạo khoản thu độc lập → mã TTxxxxxx + QR + trang QR; khách chuyển khoản vào tài khoản Pay2S của 365home thì khoản tự "Đã thanh toán".
class PaymentCollectionTest extends TestCase
{
    use DatabaseTransactions;

    private const ACCOUNT = '8800112233';

    private const TOKEN = 'collection-hook-token-zzzz';

    private int $seq = 930000000;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Settings\SePaySettings::fake(['enabled' => null, 'webhook_secret' => null, 'code_prefix' => null, 'api_token' => null, 'min_amount' => null]);
        $this->fakePay2S(true);
        config(['pay2s.api_token' => null, 'pay2s.enabled' => true]);
    }

    private function fakePay2S(bool $withAccount, ?string $gateway = 'payos'): void
    {
        Pay2SSettings::fake([
            'enabled' => null, 'api_token' => null, 'default_gateway' => $gateway,
            'platform_bank_code' => $withAccount ? 'VCB' : null, 'platform_bank_bin' => $withAccount ? '970436' : null,
            'platform_account_number' => $withAccount ? self::ACCOUNT : null, 'platform_account_holder' => $withAccount ? 'CONG TY 365HOME' : null,
            'platform_webhook_token' => $withAccount ? self::TOKEN : null,
        ]);
    }

    private function admin(): array
    {
        return ['Authorization' => 'Bearer ' . User::role('super_admin')->firstOrFail()->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function create(array $override = []): array
    {
        return $this->postJson('/api/admin/payment-collections', $override + ['amount' => 250000, 'description' => 'Phi dich vu thang 12', 'customer_name' => 'Nguyen Van A', 'customer_phone' => '0900000001'], $this->admin())
            ->assertCreated()->json('data');
    }

    private function hook(string $content, int $amount, ?int &$id = null)
    {
        $id = ++$this->seq;
        $tx = ['id' => $id, 'gateway' => 'VCB', 'transactionDate' => now()->format('Y-m-d H:i:s'), 'transactionNumber' => (string) random_int(10000, 99999),
            'accountNumber' => self::ACCOUNT, 'content' => 'NGUYEN VAN A ' . $content, 'transferType' => 'IN', 'transferAmount' => $amount];

        return $this->call('POST', '/api/webhook/pay2s', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN],
            json_encode(['transactions' => [$tx]]));
    }

    private function row(int $id): ?SePayTransaction
    {
        return SePayTransaction::query()->where('provider', 'pay2s')->where('sepay_id', $id)->first();
    }

    public function test_admin_creates_a_collection_with_code_qr_and_a_public_link(): void
    {
        $data = $this->create(['expires_in_hours' => 24]);

        $this->assertMatchesRegularExpression('/^TT[A-HJ-NP-Z2-9]{6}$/', $data['code']);
        $this->assertSame($data['code'], $data['transfer_content']);
        $this->assertSame(250000, $data['amount']);
        $this->assertSame('pending', $data['status']);
        $this->assertSame(self::ACCOUNT, $data['bank']['account_number']);
        $this->assertStringStartsWith('000201', $data['qr_code']);
        $this->assertStringContainsString('/thanh-toan-chuyen-khoan/collection/' . $data['code'], $data['checkout_url']);

        $this->get($data['checkout_url'])->assertOk()->assertSee('Phi dich vu thang 12')->assertSee(self::ACCOUNT)->assertSee('250.000');
        $this->getJson($data['checkout_url'] . '/trang-thai')->assertOk()->assertJsonPath('data.status', 'pending');
        $this->getJson('/api/admin/payment-collections?search=' . $data['code'], $this->admin())->assertOk()->assertJsonPath('data.0.code', $data['code']);
    }

    public function test_it_works_even_while_the_default_gateway_is_still_payos(): void
    {
        $this->fakePay2S(true, 'payos');

        $this->create();
    }

    public function test_creation_is_refused_without_a_platform_account_or_with_a_bad_amount_or_for_non_super_admins(): void
    {
        $this->postJson('/api/admin/payment-collections', ['amount' => 1000], $this->admin())->assertCreated();
        $this->postJson('/api/admin/payment-collections', ['amount' => 10], $this->admin())->assertStatus(422);

        $this->fakePay2S(false);
        $this->postJson('/api/admin/payment-collections', ['amount' => 50000], $this->admin())->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->fakePay2S(true);
        $plain = User::query()->whereDoesntHave('roles', fn ($q) => $q->where('name', config('filament-shield.super_admin.name', 'super_admin')))->whereNotNull('partner_id')->first();
        if ($plain) {
            $this->app['auth']->forgetGuards(); // mỗi request một danh tính: bỏ user đã nhớ ở guard từ request trước
            $this->postJson('/api/admin/payment-collections', ['amount' => 50000], ['Authorization' => 'Bearer ' . $plain->createToken('t')->plainTextToken, 'Accept' => 'application/json'])->assertStatus(403);
        }
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/admin/payment-collections', ['amount' => 50000])->assertStatus(401);
    }

    public function test_a_transfer_with_the_code_and_enough_money_marks_it_paid_and_resend_is_not_double_counted(): void
    {
        $data = $this->create();

        // Thiếu tiền: chưa ghi nhận, để đối soát.
        $this->hook($data['code'], 100000, $short)->assertOk();
        $this->assertSame('rejected', $this->row($short)->status);
        $this->assertSame('pending', PaymentCollection::query()->where('code', $data['code'])->value('status'));

        $this->hook('chuyen khoan ' . $data['code'], 250000, $ok)->assertOk();
        $this->assertSame('processed', $this->row($ok)->status);
        $collection = PaymentCollection::query()->where('code', $data['code'])->first();
        $this->assertSame('paid', $collection->status);
        $this->assertSame(250000, $collection->paid_amount);
        $this->assertNotNull($collection->paid_at);
        $this->getJson($data['checkout_url'] . '/trang-thai')->assertJsonPath('data.status', 'paid');

        // Webhook gửi lại cùng giao dịch: không xử lý lần hai.
        $this->call('POST', '/api/webhook/pay2s', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN],
            json_encode(['transactions' => [['id' => $ok, 'accountNumber' => self::ACCOUNT, 'content' => $data['code'], 'transferType' => 'IN', 'transferAmount' => 250000]]]))->assertOk();
        $this->assertSame(1, SePayTransaction::query()->where('provider', 'pay2s')->where('sepay_id', $ok)->count());

        // Chuyển lần hai cho khoản đã trả: từ chối để hoàn tiền thủ công.
        $this->hook($data['code'], 250000, $dup)->assertOk();
        $this->assertSame('rejected', $this->row($dup)->status);
        $this->assertStringContainsString('chuyển trùng', $this->row($dup)->failure_reason);
    }

    public function test_a_cancelled_collection_no_longer_accepts_money_and_a_paid_one_cannot_be_cancelled(): void
    {
        $data = $this->create();
        $this->postJson("/api/admin/payment-collections/{$data['id']}/cancel", [], $this->admin())->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson($data['checkout_url'] . '/trang-thai')->assertJsonPath('data.status', 'closed');

        $this->hook($data['code'], 250000, $id)->assertOk();
        $this->assertSame('rejected', $this->row($id)->status);
        $this->assertStringContainsString('đã bị huỷ', $this->row($id)->failure_reason);
        $this->assertSame('cancelled', PaymentCollection::query()->where('code', $data['code'])->value('status'));

        $paid = $this->create();
        $this->hook($paid['code'], 250000)->assertOk();
        $this->postJson("/api/admin/payment-collections/{$paid['id']}/cancel", [], $this->admin())->assertStatus(422);
    }

    public function test_an_expired_collection_shows_closed_but_late_money_is_still_recorded(): void
    {
        $data = $this->create(['expires_in_hours' => 1]);
        PaymentCollection::query()->where('code', $data['code'])->update(['expires_at' => now()->subMinute()]);

        $this->getJson($data['checkout_url'] . '/trang-thai')->assertJsonPath('data.status', 'closed');
        $this->getJson("/api/admin/payment-collections/{$data['id']}", $this->admin())->assertJsonPath('data.expired', true);

        $this->hook($data['code'], 250000, $id)->assertOk();
        $this->assertSame('processed', $this->row($id)->status);
        $this->assertSame('paid', PaymentCollection::query()->where('code', $data['code'])->value('status'));
    }

    public function test_the_cms_pages_list_create_view_the_qr_and_cancel(): void
    {
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs(User::role('super_admin')->firstOrFail());

        \Livewire\Livewire::test(\Modules\Payment\App\Filament\Resources\PaymentCollectionResource\Pages\CreatePaymentCollection::class)->assertSuccessful()
            ->fillForm(['amount' => 180000, 'description' => 'Thu thu cong', 'customer_name' => 'Khach CMS', 'expires_in_hours' => 24])
            ->call('create')->assertHasNoFormErrors();

        $collection = PaymentCollection::query()->where('customer_name', 'Khach CMS')->firstOrFail();
        $this->assertSame(180000, $collection->amount);
        $this->assertMatchesRegularExpression('/^TT[A-HJ-NP-Z2-9]{6}$/', $collection->code);

        $list = \Livewire\Livewire::test(\Modules\Payment\App\Filament\Resources\PaymentCollectionResource\Pages\ListPaymentCollections::class)
            ->assertSuccessful()->assertCanSeeTableRecords([$collection])
            ->callTableAction('qr', $collection)->assertSuccessful();
        $list->callTableAction('cancel', $collection)->assertHasNoTableActionErrors();

        $this->assertSame('cancelled', $collection->fresh()->status);
    }
}

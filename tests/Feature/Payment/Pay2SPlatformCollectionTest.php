<?php

namespace Tests\Feature\Payment;

use App\Models\Partner;
use App\Models\PartnerEscrowDeposit;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Services\EscrowPayosService;
use App\Services\Payment\PayOsAccountResolver;
use App\Services\Payment\PlatformCollectionService;
use App\Services\Payment\SePayGateway;
use App\Services\Payment\SePayService;
use App\Settings\Pay2SSettings;
use App\Support\Pay2SConfig;
use App\Support\VietQrPayload;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\PartnerSePayAccount;
use Modules\Payment\Entities\SePayPaymentLink;
use Modules\Payment\Entities\SePayTransaction;
use Tests\TestCase;

// Chuyển PayOS → Pay2S: khi chọn cổng Pay2S + khai báo tài khoản ngân hàng của 365home, đơn không có kênh riêng của đối tác và các khoản 365 thu hộ (gói dịch vụ, ký quỹ...)
// đi qua Pay2S; tiền vào được khớp theo mã trong nội dung chuyển khoản. Chọn lại PayOS = hành vi cũ.
class Pay2SPlatformCollectionTest extends TestCase
{
    use DatabaseTransactions;

    private const PLATFORM_ACCOUNT = '8800112233';

    private const PLATFORM_TOKEN = 'platform-hook-token-zzzzzzzz';

    private Partner $partner;

    private int $branchId;

    private int $seq = 910000000;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Settings\SePaySettings::fake(['enabled' => null, 'webhook_secret' => null, 'code_prefix' => null, 'api_token' => null, 'min_amount' => null]);
        $this->fakePay2S('pay2s');
        config(['sepay.code_prefix' => 'DH', 'pay2s.api_token' => null, 'pay2s.enabled' => true, 'payos.client_id' => null, 'payos.api_key' => null, 'payos.checksum_key' => null]);

        // Đối tác thường, chưa có kênh riêng nào: 365 thu hộ.
        $this->partner = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->firstOrFail();
        $this->partner->forceFill(['payment_flow_effective_at' => now()->subDay()])->save();
        PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->delete();
        \Modules\Payment\Entities\PartnerPayOsAccount::query()->where('partner_id', $this->partner->id)->delete();

        $this->branchId = Category::withoutGlobalScopes()->forceCreate(['name' => 'CN thu ho ' . Str::random(4), 'slug' => 'cn-thu-ho-' . Str::random(6), 'category_type' => 'product', 'status' => 1, 'partner_id' => $this->partner->id])->id;
    }

    private function fakePay2S(?string $gateway, bool $withAccount = true): void
    {
        Pay2SSettings::fake([
            'enabled' => null, 'api_token' => null, 'default_gateway' => $gateway,
            'platform_bank_code' => $withAccount ? 'VCB' : null, 'platform_bank_bin' => $withAccount ? '970436' : null,
            'platform_account_number' => $withAccount ? self::PLATFORM_ACCOUNT : null, 'platform_account_holder' => $withAccount ? 'CONG TY 365HOME' : null,
            'platform_webhook_token' => $withAccount ? self::PLATFORM_TOKEN : null,
        ]);
    }

    private function order(): Order
    {
        return Order::withoutGlobalScopes()->forceCreate([
            'partner_id' => $this->partner->id, 'category_id' => $this->branchId, 'status' => 'pending', 'amount' => 1000000, 'full_amount' => 1000000,
            'buyer_name' => 'Khach', 'buyer_phone' => '0900000000', 'payment_method' => 'PayOS', 'order_status' => 'pending',
        ]);
    }

    private function hook(array $transactions, ?string $token = self::PLATFORM_TOKEN)
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($token !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        return $this->call('POST', '/api/webhook/pay2s', [], [], [], $headers, json_encode(['transactions' => $transactions]));
    }

    private function tx(string $content, int $amount, ?int $id = null, string $account = self::PLATFORM_ACCOUNT): array
    {
        return [
            'id' => $id ?? ++$this->seq, 'gateway' => 'VCB', 'transactionDate' => now()->format('Y-m-d H:i:s'), 'transactionNumber' => (string) random_int(10000, 99999),
            'accountNumber' => $account, 'content' => 'NGUYEN VAN A chuyen khoan ' . $content, 'transferType' => 'IN', 'transferAmount' => $amount,
        ];
    }

    private function row(int $id): ?SePayTransaction
    {
        return SePayTransaction::query()->where('provider', 'pay2s')->where('sepay_id', $id)->first();
    }

    // ── Công tắc cổng ──────────────────────────────────────────────────────

    public function test_default_gateway_is_payos_until_switched_and_switching_back_restores_payos(): void
    {
        $order = $this->order();

        $this->fakePay2S('payos');
        $this->assertFalse(Pay2SConfig::collectsViaPay2S());
        $this->assertNull(app(SePayService::class)->accountFor($order));
        $this->assertSame('payos', app(SePayService::class)->paymentFields($order)['payment_provider']);

        $this->fakePay2S('pay2s');
        $this->assertTrue(Pay2SConfig::collectsViaPay2S());
        $this->assertTrue(app(SePayService::class)->accountFor($order)->isPlatform());
        $this->assertSame('pay2s', app(SePayService::class)->paymentFields($order)['payment_provider']);

        $this->fakePay2S('payos');
        $this->assertNull(app(SePayService::class)->accountFor($order));
    }

    public function test_choosing_pay2s_without_a_platform_account_keeps_payos(): void
    {
        $this->fakePay2S('pay2s', withAccount: false);

        $this->assertNull(Pay2SConfig::platformAccount());
        $this->assertNull(app(SePayService::class)->accountFor($this->order()));
    }

    public function test_the_pay2s_master_switch_off_keeps_payos(): void
    {
        config(['pay2s.enabled' => false]);
        Pay2SSettings::fake(['enabled' => false, 'api_token' => null, 'default_gateway' => 'pay2s', 'platform_bank_code' => 'VCB', 'platform_bank_bin' => '970436',
            'platform_account_number' => self::PLATFORM_ACCOUNT, 'platform_account_holder' => 'X', 'platform_webhook_token' => self::PLATFORM_TOKEN]);

        $this->assertNull(app(SePayService::class)->accountFor($this->order()));
    }

    // ── Đơn đặt phòng thu hộ qua tài khoản 365 ─────────────────────────────

    public function test_a_room_order_without_a_partner_channel_is_collected_by_the_platform_through_pay2s(): void
    {
        $order = $this->order();
        $gateway = PayOsAccountResolver::forOrder($order);

        $this->assertInstanceOf(SePayGateway::class, $gateway);
        $this->assertSame('platform', $gateway->collectedBy());
        $this->assertFalse(app(SePayService::class)->collectsToPartner($order));
        $this->assertTrue(app(SePayService::class)->usesSePay($order));

        $link = SePayPaymentLink::query()->where('order_id', $order->id)->first();
        $this->assertNull($link);

        $response = $gateway->createPaymentLink(['orderCode' => (int) $order->order_code, 'amount' => 1000000, 'expiredAt' => now()->addMinutes(15)->timestamp]);
        $link = SePayPaymentLink::query()->where('order_id', $order->id)->firstOrFail();

        $this->assertNull($link->partner_sepay_account_id);
        $this->assertStringContainsString('/thanh-toan-sepay/', $response['checkoutUrl']);

        $payload = app(SePayService::class)->payloadFor($link);
        $this->assertSame('pay2s', $payload['provider']);
        $this->assertSame(self::PLATFORM_ACCOUNT, $payload['bank']['account_number']);
    }

    public function test_money_into_the_platform_account_confirms_the_room_order_and_marks_it_platform_collected(): void
    {
        $order = $this->order();
        $link = SePayPaymentLink::query()->findOrFail(
            (function () use ($order) {
                PayOsAccountResolver::forOrder($order)->createPaymentLink(['orderCode' => (int) $order->order_code, 'amount' => 1000000, 'expiredAt' => now()->addMinutes(15)->timestamp]);

                return SePayPaymentLink::query()->where('order_id', $order->id)->value('id');
            })()
        );

        $this->hook([$this->tx($link->paymentCode(), 1000000, 910000001)])->assertOk();

        $this->assertNotSame('pending', $order->fresh()->status);
        $this->assertSame('processed', $this->row(910000001)->status);
        $this->assertSame(SePayPaymentLink::PAID, $link->fresh()->status);
    }

    public function test_a_partner_with_its_own_pay2s_account_keeps_collecting_to_the_partner(): void
    {
        PartnerSePayAccount::query()->create([
            'partner_id' => $this->partner->id, 'provider' => 'pay2s', 'is_active' => true, 'bank_code' => 'ACB', 'bank_bin' => '970416',
            'account_number' => '5566778899', 'account_holder' => 'DOI TAC', 'webhook_token' => 'partner-hook-token-yyyyyyyy',
        ]);
        $order = $this->order();

        $account = app(SePayService::class)->accountFor($order);
        $this->assertFalse($account->isPlatform());
        $this->assertSame('5566778899', $account->account_number);
        $this->assertTrue(app(SePayService::class)->collectsToPartner($order));
    }

    public function test_a_partner_with_an_active_payos_channel_but_no_pay2s_account_keeps_payos(): void
    {
        \Modules\Payment\Entities\PartnerPayOsAccount::query()->create([
            'partner_id' => $this->partner->id, 'client_id' => 'c', 'api_key' => 'k', 'checksum_key' => 's', 'is_active' => true,
        ]);

        $order = $this->order();

        // Tiền kênh PayOS riêng của đối tác phải về đối tác: chưa có Pay2S thì không được rơi về tài khoản 365.
        $this->assertNull(app(SePayService::class)->accountFor($order));
    }

    // ── Ký quỹ ─────────────────────────────────────────────────────────────

    public function test_escrow_deposit_gets_pay2s_instructions_and_is_credited_by_the_transfer_content(): void
    {
        $deposit = app(EscrowPayosService::class)->createDeposit($this->partner, 5000000, null);

        $this->assertNull($deposit->payos_order_code);
        $this->assertSame(self::PLATFORM_ACCOUNT, $deposit->payos_account_number);
        $this->assertSame('970436', $deposit->payos_bank_bin);
        $this->assertStringContainsString($deposit->transaction_code, (string) $deposit->payos_checkout_url);
        $this->assertStringStartsWith('000201', (string) $deposit->payos_qr_code);

        $before = (int) $this->partner->fresh()->escrow_balance;
        $this->hook([$this->tx($deposit->transaction_code, 5000000, 910000010)])->assertOk();

        $this->assertSame(PartnerEscrowDeposit::STATUS_PAID, $deposit->fresh()->status);
        $this->assertSame($before + 5000000, (int) $this->partner->fresh()->escrow_balance);
        $this->assertSame('processed', $this->row(910000010)->status);

        // Gửi lại cùng giao dịch: không cộng hai lần.
        $this->hook([$this->tx($deposit->transaction_code, 5000000, 910000010)])->assertOk();
        $this->assertSame($before + 5000000, (int) $this->partner->fresh()->escrow_balance);
    }

    public function test_a_second_transfer_for_an_already_paid_deposit_is_rejected_for_manual_refund(): void
    {
        $deposit = app(EscrowPayosService::class)->createDeposit($this->partner, 3000000, null);
        $this->hook([$this->tx($deposit->transaction_code, 3000000, 910000020)])->assertOk();
        $balance = (int) $this->partner->fresh()->escrow_balance;

        $this->hook([$this->tx($deposit->transaction_code, 3000000, 910000021)])->assertOk();

        $this->assertSame('rejected', $this->row(910000021)->status);
        $this->assertStringContainsString('chuyển trùng', $this->row(910000021)->failure_reason);
        $this->assertSame($balance, (int) $this->partner->fresh()->escrow_balance);
    }

    public function test_the_deposit_falls_back_to_payos_when_the_gateway_is_switched_back(): void
    {
        $this->fakePay2S('payos');

        $deposit = app(EscrowPayosService::class)->createDeposit($this->partner, 2000000, null);

        // PayOS chưa cấu hình trong test → không tạo link (hành vi cũ), nhưng chắc chắn không có hướng dẫn Pay2S.
        $this->assertNull($deposit->payos_account_number);
        $this->assertNull($deposit->payos_qr_code);
    }

    // ── Gói dịch vụ ────────────────────────────────────────────────────────

    private function subscriptionPayment(int $amount): SubscriptionPayment
    {
        $plan = SubscriptionPlan::create(['code' => 'p2s-' . Str::random(6), 'name' => 'Gói test Pay2S', 'partner_type' => $this->partner->partner_type, 'price_vnd' => $amount, 'period_months' => 1, 'is_active' => true]);

        return SubscriptionPayment::create([
            'partner_id' => $this->partner->id, 'plan_id' => $plan->id, 'transaction_code' => 'SB' . substr(str_replace(['I', 'O', '0', '1'], 'A', strtoupper(Str::random(8))), 0, 6),
            'amount_vnd' => $amount, 'months' => 1, 'status' => SubscriptionPayment::STATUS_PENDING, 'source' => 'test',
        ]);
    }

    public function test_subscription_payment_is_activated_by_pay2s_and_underpayment_is_rejected(): void
    {
        $payment = $this->subscriptionPayment(500000);
        app(\App\Services\SubscriptionPayosService::class)->createLink($payment);
        $payment->refresh();

        $this->assertSame(self::PLATFORM_ACCOUNT, $payment->payos_account_number);
        $this->assertStringStartsWith('000201', (string) $payment->payos_qr_code);

        // Thiếu tiền: chưa kích hoạt, ghi từ chối để xử lý thủ công.
        $this->hook([$this->tx($payment->transaction_code, 100000, 910000030)])->assertOk();
        $this->assertSame('rejected', $this->row(910000030)->status);
        $this->assertSame(SubscriptionPayment::STATUS_PENDING, $payment->fresh()->status);

        $this->hook([$this->tx($payment->transaction_code, 500000, 910000031)])->assertOk();
        $this->assertSame('processed', $this->row(910000031)->status);
        $this->assertSame(SubscriptionPayment::STATUS_PAID, $payment->fresh()->status);
    }

    // ── Nhận diện mã, token, trang QR ──────────────────────────────────────

    public function test_detect_recognises_every_platform_code_and_ignores_unrelated_content(): void
    {
        $svc = app(PlatformCollectionService::class);

        $this->assertSame(['kind' => 'subscription', 'code' => 'SBABCD23'], $svc->detect('CK SBABCD23 THANH TOAN', true));
        $this->assertSame(['kind' => 'escrow', 'code' => 'KQABCD23'], $svc->detect('KQABCD23', true));
        $this->assertSame(['kind' => 'settlement', 'code' => 'DS2611-AB12C'], $svc->detect('NOP HOA HONG DS2611AB12C', true));
        $this->assertSame(['kind' => 'settlement', 'code' => 'DS2611-AB12C'], $svc->detect('DS2611-AB12C', true));
        $this->assertSame(['kind' => 'vehicle', 'code' => 'XE261127AB12'], $svc->detect('thue xe XE261127AB12', true));
        $this->assertSame('minihouse', $svc->detect('MH912345678 tien phong', true)['kind']);

        $this->assertNull($svc->detect('chuyen tien an trua', true));
        // Tiền vào tài khoản KHÔNG phải của 365: chỉ nhận mã hoá đơn MiniHouse (tài khoản riêng của toà), không nhận mã gói/ký quỹ.
        $this->assertNull($svc->detect('KQABCD23', false));
    }

    public function test_money_with_an_unknown_content_is_logged_for_reconciliation_and_a_wrong_token_is_refused(): void
    {
        // Tiền vào đúng tài khoản 365 nhưng nội dung không mang mã nào: ghi "rejected" để đối soát (như SePay/Pay2S của đối tác), không đổi khoản nào.
        $this->hook([$this->tx('chuyen tien an trua', 12345, 910000040)])->assertOk();
        $this->assertSame('rejected', $this->row(910000040)->status);

        $this->hook([$this->tx('KQABCD23', 1000, 910000041)], 'wrong-token')->assertStatus(401);
        $this->assertNull($this->row(910000041));
    }

    public function test_platform_codes_into_a_partner_account_are_not_processed(): void
    {
        $deposit = app(EscrowPayosService::class)->createDeposit($this->partner, 1000000, null);
        PartnerSePayAccount::query()->create([
            'partner_id' => $this->partner->id, 'provider' => 'pay2s', 'is_active' => true, 'bank_code' => 'ACB', 'bank_bin' => '970416',
            'account_number' => '5566778800', 'account_holder' => 'DOI TAC', 'webhook_token' => 'partner-hook-token-xxxxxxxx',
        ]);

        // Chuyển nhầm mã ký quỹ vào tài khoản đối tác: không được cộng ký quỹ.
        $this->hook([$this->tx($deposit->transaction_code, 1000000, 910000050, '5566778800')], 'partner-hook-token-xxxxxxxx')->assertOk();

        $this->assertSame(PartnerEscrowDeposit::STATUS_PENDING, $deposit->fresh()->status);
    }

    public function test_the_public_qr_page_shows_the_instructions_and_the_status_flips_to_paid(): void
    {
        $deposit = app(EscrowPayosService::class)->createDeposit($this->partner, 4000000, null);
        $url = $deposit->payos_checkout_url;

        $this->get($url)->assertOk()->assertSee(self::PLATFORM_ACCOUNT)->assertSee($deposit->transaction_code)->assertSee('4.000.000');
        $this->getJson($url . '/trang-thai')->assertOk()->assertJsonPath('data.status', 'pending');

        $this->hook([$this->tx($deposit->transaction_code, 4000000, 910000060)])->assertOk();
        $this->getJson($url . '/trang-thai')->assertOk()->assertJsonPath('data.status', 'paid');

        $this->get(route('platform-collect.show', ['kind' => 'escrow', 'code' => 'KQXXXXXX']))->assertNotFound();
        $this->get('/thanh-toan-chuyen-khoan/unknown/KQXXXXXX')->assertNotFound();
    }

    public function test_vietqr_payload_has_a_valid_crc_and_carries_the_amount_and_content(): void
    {
        $this->assertSame('29B1', VietQrPayload::crc16('123456789'));

        $qr = VietQrPayload::build('970436', '8800112233', 150000, 'KQABCD23');

        $this->assertStringStartsWith('00020101021238', $qr);
        $this->assertStringContainsString('0010A000000727', $qr);
        $this->assertStringContainsString('970436', $qr);
        $this->assertStringContainsString('8800112233', $qr);
        $this->assertStringContainsString('5406150000', $qr);
        $this->assertStringContainsString('0808KQABCD23', $qr);
        $this->assertSame(VietQrPayload::crc16(substr($qr, 0, -4)), substr($qr, -4));
    }

    public function test_a_partner_webhook_token_cannot_report_money_into_the_platform_account(): void
    {
        $deposit = app(EscrowPayosService::class)->createDeposit($this->partner, 9000000, null);
        PartnerSePayAccount::query()->create([
            'partner_id' => $this->partner->id, 'provider' => 'pay2s', 'is_active' => true, 'bank_code' => 'ACB', 'bank_bin' => '970416',
            'account_number' => '5566778811', 'account_holder' => 'DOI TAC', 'webhook_token' => 'partner-forge-token-wwwwwwww',
        ]);

        // Đối tác có token hợp lệ của MÌNH nhưng khai giao dịch vào tài khoản 365home để tự cộng ký quỹ: phải bị bỏ qua hoàn toàn.
        $before = (int) $this->partner->fresh()->escrow_balance;
        $this->hook([$this->tx($deposit->transaction_code, 9000000, 910000070)], 'partner-forge-token-wwwwwwww')->assertOk();

        $this->assertNull($this->row(910000070));
        $this->assertSame(PartnerEscrowDeposit::STATUS_PENDING, $deposit->fresh()->status);
        $this->assertSame($before, (int) $this->partner->fresh()->escrow_balance);

        // Đúng token của 365home thì xử lý bình thường.
        $this->hook([$this->tx($deposit->transaction_code, 9000000, 910000071)])->assertOk();
        $this->assertSame(PartnerEscrowDeposit::STATUS_PAID, $deposit->fresh()->status);
    }

    public function test_money_for_a_platform_collected_order_arriving_in_a_partner_account_is_not_confirmed(): void
    {
        $order = $this->order();
        PayOsAccountResolver::forOrder($order)->createPaymentLink(['orderCode' => (int) $order->order_code, 'amount' => 1000000, 'expiredAt' => now()->addMinutes(15)->timestamp]);
        $link = SePayPaymentLink::query()->where('order_id', $order->id)->firstOrFail();

        // Đối tác chỉ có tài khoản theo chi nhánh khác: đơn này vẫn do 365 thu hộ.
        $other = Category::withoutGlobalScopes()->forceCreate(['name' => 'CN khac ' . Str::random(4), 'slug' => 'cn-khac-' . Str::random(6), 'category_type' => 'product', 'status' => 1, 'partner_id' => $this->partner->id])->id;
        PartnerSePayAccount::query()->create([
            'partner_id' => $this->partner->id, 'category_id' => $other, 'provider' => 'pay2s', 'is_active' => true, 'bank_code' => 'ACB', 'bank_bin' => '970416',
            'account_number' => '5566778822', 'account_holder' => 'DOI TAC', 'webhook_token' => 'partner-branch-token-vvvvvvvv',
        ]);

        $this->hook([$this->tx($link->paymentCode(), 1000000, 910000080, '5566778822')], 'partner-branch-token-vvvvvvvv')->assertOk();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('rejected', $this->row(910000080)->status);
        $this->assertStringContainsString('365home thu hộ', $this->row(910000080)->failure_reason);
    }
}

<?php

namespace Tests\Feature\Payment;

use App\Models\Partner;
use App\Services\Payment\PayOsAccountResolver;
use App\Services\Payment\Pay2SService;
use App\Services\Payment\SePayGateway;
use App\Services\Payment\SePayService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\PartnerSePayAccount;
use Modules\Payment\Entities\SePayPaymentLink;
use Modules\Payment\Entities\SePayTransaction;
use Tests\TestCase;

// Pay2S là cổng thứ hai (cùng luồng với SePay): đối tác chọn Pay2S → đơn thu qua chuyển khoản vào tài khoản của đối tác và được xác nhận tự động qua
// webhook Pay2S (token Bearer riêng từng webhook) hoặc kéo giao dịch từ API Pay2S; mọi kiểm tra (tài khoản, mã, số tiền, trạng thái đơn) giống SePay.
class Pay2SFlowTest extends TestCase
{
    use DatabaseTransactions;

    private const ACCOUNT = '0099887766';

    private const TOKEN = 'pay2s-hook-token-aaaaaaaaaaaa';

    private Partner $partner;

    private int $branchId;

    private int $seq = 810000000;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Settings\SePaySettings::fake(['enabled' => null, 'webhook_secret' => null, 'code_prefix' => null, 'api_token' => null, 'min_amount' => null]);
        \App\Settings\Pay2SSettings::fake(['enabled' => null, 'api_token' => null]); // tách khỏi cấu hình CMS thật
        config(['sepay.code_prefix' => 'DH', 'pay2s.api_token' => null, 'pay2s.enabled' => true]);

        $this->partner = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->firstOrFail();
        $this->partner->forceFill(['payment_flow_effective_at' => now()->subDay()])->save();

        $this->branchId = Category::withoutGlobalScopes()->forceCreate(['name' => 'CN pay2s ' . Str::random(4), 'slug' => 'cn-pay2s-' . Str::random(6), 'category_type' => 'product', 'status' => 1, 'partner_id' => $this->partner->id])->id;
        $this->account($this->partner->id, self::ACCOUNT);
    }

    private function account(string $partnerId, string $number, string $provider = 'pay2s', ?string $token = self::TOKEN, string $bank = 'VCB', string $bin = '970436'): PartnerSePayAccount
    {
        PartnerSePayAccount::query()->where('partner_id', $partnerId)->delete();
        PartnerSePayAccount::query()->where('account_number', $number)->delete();

        return PartnerSePayAccount::query()->create([
            'partner_id' => $partnerId, 'provider' => $provider, 'is_active' => true, 'bank_code' => $bank, 'bank_bin' => $bin,
            'account_number' => $number, 'account_holder' => 'TEST', 'webhook_token' => $token,
        ]);
    }

    private function order(): Order
    {
        return Order::withoutGlobalScopes()->forceCreate([
            'partner_id' => $this->partner->id, 'category_id' => $this->branchId, 'status' => 'pending', 'amount' => 1000000, 'full_amount' => 1000000,
            'buyer_name' => 'Khach', 'buyer_phone' => '0900000000', 'payment_method' => 'PayOS', 'order_status' => 'pending',
        ]);
    }

    private function link(Order $order): SePayPaymentLink
    {
        $gateway = PayOsAccountResolver::forOrder($order);
        $this->assertInstanceOf(SePayGateway::class, $gateway);

        $response = $gateway->createPaymentLink(['orderCode' => (int) $order->order_code, 'amount' => $order->depositDueAmount(), 'expiredAt' => now()->addMinutes(15)->timestamp]);

        return SePayPaymentLink::query()->where('order_id', $order->id)->where('ref_code', $response['orderCode'])->firstOrFail();
    }

    private function tx(SePayPaymentLink $link, int $amount, ?int $id = null, string $type = 'IN', string $account = self::ACCOUNT): array
    {
        return [
            'id' => $id ?? ++$this->seq, 'gateway' => 'ACB', 'transactionDate' => now()->format('Y-m-d H:i:s'), 'transactionNumber' => (string) random_int(10000, 99999),
            'accountNumber' => $account, 'content' => 'NGUYEN VAN A chuyen khoan ' . $link->paymentCode(), 'transferType' => $type, 'transferAmount' => $amount, 'checksum' => md5((string) $this->seq),
        ];
    }

    private function hook(array $transactions, ?string $token = self::TOKEN)
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($token !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        return $this->call('POST', '/api/webhook/pay2s', [], [], [], $headers, json_encode(['transactions' => $transactions]));
    }

    private function row(int $id, string $provider = 'pay2s'): ?SePayTransaction
    {
        return SePayTransaction::query()->where('provider', $provider)->where('sepay_id', $id)->first();
    }

    // ── Chọn kênh ──────────────────────────────────────────────────────────

    public function test_a_partner_on_pay2s_gets_the_bank_transfer_gateway_and_pay2s_payment_fields(): void
    {
        $order = $this->order();
        $link = $this->link($order);
        $fields = app(SePayService::class)->paymentFields($order);

        $this->assertSame('pay2s', $fields['payment_provider']);
        $this->assertNull($fields['sepay']);
        $this->assertSame($link->paymentCode(), $fields['pay2s']['payment_code']);
        $this->assertSame('pay2s', $fields['pay2s']['provider']);
        $this->assertSame(self::ACCOUNT, $fields['pay2s']['bank']['account_number']);
    }

    public function test_a_sepay_partner_keeps_the_old_response_shape(): void
    {
        $this->account($this->partner->id, self::ACCOUNT, 'sepay', null);
        $fields = app(SePayService::class)->paymentFields($this->order());

        $this->assertSame('sepay', $fields['payment_provider']);
        $this->assertArrayNotHasKey('pay2s', $fields);
    }

    public function test_switching_off_pay2s_globally_falls_back_to_payos(): void
    {
        config(['pay2s.enabled' => false]);
        $order = $this->order();

        $this->assertNotInstanceOf(SePayGateway::class, PayOsAccountResolver::forOrder($order));
        $this->assertSame('payos', app(SePayService::class)->paymentFields($order)['payment_provider']);
    }

    public function test_vietinbank_content_has_no_sevqr_prefix_on_pay2s_but_keeps_it_on_sepay(): void
    {
        $account = $this->account($this->partner->id, self::ACCOUNT, 'pay2s', self::TOKEN, 'CTG', '970415');
        $this->assertSame('DH12345678', app(SePayService::class)->transferContent($account, 'DH12345678'));

        $account->update(['provider' => 'sepay']);
        $this->assertSame('SEVQR DH12345678', app(SePayService::class)->transferContent($account->fresh(), 'DH12345678'));
    }

    // ── Webhook ───────────────────────────────────────────────────────────

    public function test_webhook_without_or_with_a_wrong_token_is_rejected(): void
    {
        $link = $this->link($this->order());

        $this->hook([$this->tx($link, 1000000)], null)->assertStatus(401);
        $this->hook([$this->tx($link, 1000000)], 'sai-token')->assertStatus(401);
        $this->assertSame('pending', Order::withoutGlobalScopes()->find($link->order_id)->status);
    }

    public function test_a_token_of_a_sepay_account_is_not_accepted_by_the_pay2s_webhook(): void
    {
        $this->account($this->partner->id, self::ACCOUNT, 'sepay', 'token-sepay-account-xx');
        $link = $this->link($this->order());

        $this->hook([$this->tx($link, 1000000)], 'token-sepay-account-xx')->assertStatus(401);
    }

    public function test_webhook_confirms_the_order_when_money_arrives(): void
    {
        $order = $this->order();
        $link = $this->link($order);
        $tx = $this->tx($link, 1000000);

        $this->hook([$tx])->assertOk()->assertJson(['success' => true]);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('paid', $link->fresh()->status);
        $this->assertSame('processed', $this->row($tx['id'])->status);
    }

    public function test_the_second_partner_token_also_works_each_webhook_has_its_own_token(): void
    {
        $other = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->where('id', '!=', $this->partner->id)->first();

        if (! $other) {
            $this->markTestSkipped('Cần ít nhất 2 đối tác Homestay.');
        }

        $this->account($other->id, '0011223344', 'pay2s', 'token-doi-tac-thu-hai');
        $link = $this->link($this->order());

        // Token của đối tác khác vẫn hợp lệ ở mức webhook, nhưng giao dịch vẫn phải khớp đúng tài khoản nhận của đơn.
        $this->hook([$this->tx($link, 1000000)], 'token-doi-tac-thu-hai')->assertOk();
        $this->assertSame('paid', Order::withoutGlobalScopes()->find($link->order_id)->status);
    }

    public function test_outgoing_transactions_and_test_ids_are_ignored(): void
    {
        $order = $this->order();
        $link = $this->link($order);
        $out = $this->tx($link, 1000000, null, 'OUT');
        $zero = $this->tx($link, 1000000, 0);

        $this->hook([$out, $zero])->assertOk();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($this->row($out['id']));
    }

    public function test_wrong_amount_is_stored_as_rejected_with_a_reason_and_the_order_is_untouched(): void
    {
        $order = $this->order();
        $link = $this->link($order);
        $tx = $this->tx($link, 999000);

        $this->hook([$tx])->assertOk();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('rejected', $this->row($tx['id'])->status);
        $this->assertStringContainsString('Số tiền không khớp', $this->row($tx['id'])->failure_reason);
    }

    public function test_an_account_that_is_not_declared_is_rejected(): void
    {
        $link = $this->link($this->order());
        $tx = $this->tx($link, 1000000, null, 'IN', '5555555555');

        $this->hook([$tx])->assertOk();

        $this->assertStringContainsString('chưa được khai báo', $this->row($tx['id'])->failure_reason);
    }

    public function test_a_resent_webhook_does_not_process_twice(): void
    {
        $order = $this->order();
        $link = $this->link($order);
        $tx = $this->tx($link, 1000000);

        $this->hook([$tx])->assertOk();
        $this->hook([$tx])->assertOk();

        $this->assertSame(1, SePayTransaction::query()->where('provider', 'pay2s')->where('sepay_id', $tx['id'])->count());
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_the_same_numeric_id_from_another_provider_does_not_collide(): void
    {
        $order = $this->order();
        $link = $this->link($order);
        $tx = $this->tx($link, 1000000);
        SePayTransaction::query()->create(['provider' => 'sepay', 'sepay_id' => $tx['id'], 'amount' => 1, 'status' => 'processed', 'payload' => []]);

        $this->hook([$tx])->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('processed', $this->row($tx['id'])->status);
    }

    public function test_money_for_a_sepay_declared_account_arriving_through_pay2s_is_rejected(): void
    {
        $order = $this->order();
        $this->account($this->partner->id, self::ACCOUNT, 'sepay', null);
        $link = $this->link($order);
        $tx = $this->tx($link, 1000000);

        $result = app(SePayService::class)->handleIncoming(app(Pay2SService::class)->webhookPayload($tx), $tx, 'pay2s');

        $this->assertSame('rejected', $result['status']);
        $this->assertStringContainsString('đang khai báo cổng SePay', $result['reason']);
        $this->assertSame('pending', $order->fresh()->status);
    }

    // ── Kéo giao dịch từ API Pay2S ────────────────────────────────────────

    private function fakeApi(array $transactions, int $status = 200): void
    {
        config(['pay2s.api_token' => 'api-token-xyz']);
        Http::fake(['api.pay2s.vn/*' => Http::response(['status' => true, 'messages' => 'Thành công', 'transactions' => $transactions], $status)]);
    }

    private function apiTx(SePayPaymentLink $link, int $amount, int $id): array
    {
        return [
            'id' => $id, 'transaction_date' => now()->format('Y-m-d H:i:s'), 'transaction_id' => '10439', 'account_number' => self::ACCOUNT, 'bank' => 'ACB',
            'amount' => $amount, 'description' => 'QR - ' . $link->paymentCode() . ' GD 750915', 'type' => 'IN', 'checksum' => md5((string) $id), 'detail' => [],
        ];
    }

    public function test_pull_confirms_the_order_from_the_pay2s_api_without_a_webhook(): void
    {
        $order = $this->order();
        $link = $this->link($order);
        $this->fakeApi([$this->apiTx($link, 1000000, 820000001)]);

        $this->assertSame(1, app(SePayService::class)->pull($link));

        $this->assertSame('paid', $order->fresh()->status);
        Http::assertSent(fn ($r) => $r->hasHeader('pay2s-token', 'api-token-xyz') && $r->method() === 'POST' && str_contains($r->url(), '/userapi/transactions'));
    }

    public function test_pull_ignores_outgoing_transactions_and_unrelated_money(): void
    {
        $order = $this->order();
        $link = $this->link($order);
        $out = $this->apiTx($link, 1000000, 820000002);
        $out['type'] = 'OUT';
        $unrelated = ['id' => 820000003, 'transaction_date' => now()->format('Y-m-d H:i:s'), 'account_number' => self::ACCOUNT, 'amount' => 50000, 'description' => 'tien an trua', 'type' => 'IN'];
        $this->fakeApi([$out, $unrelated]);

        $this->assertSame(0, app(Pay2SService::class)->pull());

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($this->row(820000002));
        $this->assertNull($this->row(820000003));
    }

    public function test_pull_does_nothing_without_an_api_token_or_on_api_errors(): void
    {
        $order = $this->order();
        $link = $this->link($order);

        Http::fake();
        $this->assertSame(0, app(Pay2SService::class)->pull($link));
        Http::assertNothingSent();

        config(['pay2s.api_token' => 'api-token-xyz']);
        Http::fake(['api.pay2s.vn/*' => Http::response(['status' => false, 'messages' => 'Lỗi'], 200)]);
        $this->assertSame(0, app(Pay2SService::class)->pull($link));
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_the_sync_command_pulls_pay2s_and_confirms_orders(): void
    {
        $order = $this->order();
        $link = $this->link($order);
        $this->fakeApi([$this->apiTx($link, 1000000, 820000004)]);

        $this->artisan('sepay:sync')->assertSuccessful()->expectsOutputToContain('Đã xác nhận 1 đơn từ giao dịch Pay2S');
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_the_connection_check_reports_missing_wrong_and_good_tokens(): void
    {
        $this->assertFalse(app(Pay2SService::class)->testApi()['ok']);

        config(['pay2s.api_token' => 'bad']);
        // Stub đăng ký sau không ghi đè stub trước → dùng một chuỗi phản hồi.
        Http::fake(['api.pay2s.vn/*' => Http::sequence()
            ->push(['message' => 'Unauthorized'], 401)
            ->push(['status' => true, 'messages' => 'Thành công', 'transactions' => []], 200)]);
        $this->assertStringContainsString('từ chối API Token', app(Pay2SService::class)->testApi()['message']);
        $this->assertTrue(app(Pay2SService::class)->testApi()['ok']);
    }
}

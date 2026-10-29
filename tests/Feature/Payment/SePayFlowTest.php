<?php

namespace Tests\Feature\Payment;

use App\Models\Partner;
use App\Services\OrderCommissionService;
use App\Services\Payment\PayOsAccountResolver;
use App\Services\Payment\SePayGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\BranchPayOsAccount;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\PartnerSePayAccount;
use Modules\Payment\Entities\SePayPaymentLink;
use Modules\Payment\Entities\SePayTransaction;
use Tests\TestCase;

// SePay thu tiền đặt phòng vào tài khoản đối tác và TỰ xác nhận đơn: chọn kênh, tạo yêu cầu thanh toán, webhook (đủ / cọc / còn lại / phát sinh),
// các trường hợp phải từ chối (sai tiền, trùng, đơn hết hạn, mã đã huỷ, sai tài khoản) và trang QR công khai.
class SePayFlowTest extends TestCase
{
    use DatabaseTransactions;

    private const ACCOUNT = '0011001234567';

    private Partner $partner;

    private int $branchId;

    private int $seq = 700000000;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Settings\SePaySettings::fake(['enabled' => null, 'webhook_secret' => null, 'code_prefix' => null, 'api_token' => null, 'min_amount' => null]); // tách khỏi cấu hình CMS thật
        config(['sepay.webhook_secret' => 'flow-secret', 'sepay.code_prefix' => 'DH']);

        $this->partner = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->firstOrFail();
        $this->partner->forceFill(['payment_flow_effective_at' => now()->subDay()])->save();

        $this->branchId = $this->makeBranch($this->partner->id);
        $this->account($this->partner->id, self::ACCOUNT);
    }

    private function makeBranch(string $partnerId): int
    {
        return Category::withoutGlobalScopes()->forceCreate(['name' => 'CN sepay ' . Str::random(4), 'slug' => 'cn-sepay-' . Str::random(6), 'category_type' => 'product', 'status' => 1, 'partner_id' => $partnerId])->id;
    }

    private function account(string $partnerId, string $number, bool $active = true): PartnerSePayAccount
    {
        PartnerSePayAccount::query()->where('partner_id', $partnerId)->delete();
        PartnerSePayAccount::query()->where('account_number', $number)->delete();

        return PartnerSePayAccount::query()->create(['partner_id' => $partnerId, 'is_active' => $active, 'bank_code' => 'VCB', 'bank_bin' => '970436', 'account_number' => $number, 'account_holder' => 'TEST']);
    }

    private function order(array $attrs = []): Order
    {
        return Order::withoutGlobalScopes()->forceCreate(array_merge([
            'partner_id' => $this->partner->id, 'category_id' => $this->branchId, 'status' => 'pending', 'amount' => 1000000, 'full_amount' => 1000000,
            'buyer_name' => 'Khach', 'buyer_phone' => '0900000000', 'payment_method' => 'PayOS', 'order_status' => 'pending',
        ], $attrs));
    }

    /** Tạo yêu cầu thanh toán đúng như các luồng đặt phòng làm (qua cổng của đơn). */
    private function link(Order $order, ?int $code = null, ?int $amount = null): SePayPaymentLink
    {
        $gateway = PayOsAccountResolver::forOrder($order);
        $this->assertInstanceOf(SePayGateway::class, $gateway);

        $response = $gateway->createPaymentLink([
            'orderCode' => $code ?? (int) $order->order_code, 'amount' => $amount ?? $order->depositDueAmount(), 'expiredAt' => now()->addMinutes(15)->timestamp,
        ]);

        return SePayPaymentLink::query()->where('order_id', $order->id)->where('ref_code', $response['orderCode'])->firstOrFail();
    }

    private function hook(SePayPaymentLink $link, int $amount, ?int $id = null, string $account = self::ACCOUNT, ?string $content = null)
    {
        return $this->send($link->paymentCode(), $amount, $id, $account, $content);
    }

    private function send(string $code, int $amount, ?int $id, string $account, ?string $content)
    {
        $body = json_encode([
            'id' => $id ?? ++$this->seq, 'gateway' => 'Vietcombank', 'transactionDate' => now()->format('Y-m-d H:i:s'), 'accountNumber' => $account,
            'code' => $code, 'content' => $content ?? ('NGUYEN VAN A chuyen khoan ' . $code), 'transferType' => 'in',
            'transferAmount' => $amount, 'accumulated' => 0, 'referenceCode' => 'FT' . Str::random(8),
        ]);
        $ts = now()->timestamp;

        return $this->call('POST', '/api/webhook/sepay', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SEPAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, 'flow-secret'), 'HTTP_X_SEPAY_TIMESTAMP' => (string) $ts,
        ], $body);
    }

    private function tx(int $sepayId): ?SePayTransaction
    {
        return SePayTransaction::query()->where('sepay_id', $sepayId)->first();
    }

    // ── Chọn kênh ──────────────────────────────────────────────────────────

    public function test_sepay_is_used_only_when_the_partner_enabled_it_and_is_allowed_direct_payment(): void
    {
        $order = $this->order();
        $this->assertInstanceOf(SePayGateway::class, PayOsAccountResolver::forOrder($order));

        // Tắt SePay → không dùng cổng SePay
        PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->update(['is_active' => false]);
        $this->assertNotInstanceOf(SePayGateway::class, PayOsAccountResolver::forOrder($order->fresh()));
        PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->update(['is_active' => true]);

        // Chưa có hợp đồng mẫu mới có hiệu lực → 365home vẫn thu hộ
        $this->partner->forceFill(['payment_flow_effective_at' => null])->save();
        $this->assertNotInstanceOf(SePayGateway::class, PayOsAccountResolver::forOrder($order->fresh()));
        $this->partner->forceFill(['payment_flow_effective_at' => now()->subDay()])->save();

        // Chi nhánh có PayOS riêng thì chi nhánh đó dùng PayOS
        BranchPayOsAccount::query()->create(['category_id' => $this->branchId, 'is_active' => true, 'client_id' => 'c', 'api_key' => 'a', 'checksum_key' => 'k']);
        $this->assertNotInstanceOf(SePayGateway::class, PayOsAccountResolver::forOrder($order->fresh()));
    }

    public function test_money_collected_by_is_partner_for_a_sepay_only_partner(): void
    {
        $this->assertSame(OrderCommissionService::COLLECTED_PARTNER, app(OrderCommissionService::class)->resolveCollectedBy($this->order()));

        PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->update(['is_active' => false]);
        $this->assertNotSame(OrderCommissionService::COLLECTED_PARTNER, app(OrderCommissionService::class)->resolveCollectedBy($this->order()), 'tắt SePay → không còn là tiền về thẳng đối tác (trừ khi có PayOS riêng)');
    }

    // ── Gateway ────────────────────────────────────────────────────────────

    public function test_gateway_creates_reads_and_cancels_payment_links(): void
    {
        $order = $this->order(['deposit_percent' => 30]);
        $gateway = PayOsAccountResolver::forOrder($order);
        $res = $gateway->createPaymentLink(['orderCode' => (int) $order->order_code, 'amount' => 300000, 'expiredAt' => now()->addMinutes(15)->timestamp]);

        $link = SePayPaymentLink::query()->where('order_id', $order->id)->where('ref_code', $order->order_code)->firstOrFail();
        $this->assertSame(route('sepay.checkout', ['code' => $link->code]), $res['checkoutUrl']);
        $this->assertMatchesRegularExpression('/^\d{8}$/', (string) $link->code, 'mã khách ghi khi chuyển khoản phải ngắn 8 số');
        $this->assertSame((int) $order->order_code, $res['orderCode'], 'trả lại đúng mã luồng đặt phòng như PayOS');
        $this->assertSame('PENDING', $gateway->getPaymentLinkInformation($order->order_code)['status']);

        $this->assertSame(300000, (int) $link->amount);
        $this->assertSame('DH' . $link->code, $link->paymentCode());
        $this->assertStringContainsString('amount=300000', $res['sepay']['qr_url']);
        $this->assertStringContainsString('bank=970436', $res['sepay']['qr_url']);
        $this->assertStringContainsString('des=DH' . $link->code, $res['sepay']['qr_url']);

        $gateway->cancelPaymentLink($order->order_code);
        $this->assertSame('CANCELLED', $gateway->getPaymentLinkInformation($order->order_code)['status']);

        // Tạo lại cùng mã (đơn đổi giá): làm mới yêu cầu với số tiền mới
        $gateway->createPaymentLink(['orderCode' => (int) $order->order_code, 'amount' => 450000, 'expiredAt' => now()->addMinutes(15)->timestamp]);
        $this->assertSame(450000, (int) $link->fresh()->amount);
        $this->assertSame('PENDING', $gateway->getPaymentLinkInformation($order->order_code)['status']);

        // Hết hạn
        SePayPaymentLink::query()->where('order_id', $order->id)->update(['expires_at' => now()->subMinute()]);
        $this->assertSame('EXPIRED', $gateway->getPaymentLinkInformation($order->order_code)['status']);
    }

    // ── Webhook: thành công ────────────────────────────────────────────────

    public function test_full_payment_confirms_the_order_and_replays_do_nothing(): void
    {
        $order = $this->order();
        $link = $this->link($order);

        $this->hook($link, 1000000, 700000001)->assertOk()->assertJson(['success' => true]);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('paid', $link->fresh()->status);
        $this->assertSame('processed', $this->tx(700000001)->status);
        $this->assertNotNull($link->fresh()->paid_at);

        $this->hook($link, 1000000, 700000001)->assertOk();
        $this->assertSame(1, SePayTransaction::query()->where('sepay_id', 700000001)->count());
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_deposit_then_remaining_payment_complete_the_order(): void
    {
        $order = $this->order(['deposit_percent' => 30]);
        $deposit = $this->link($order);
        $this->assertSame(300000, (int) $deposit->amount);

        $this->hook($deposit, 300000, 700000010)->assertOk();
        $this->assertSame('deposit', $order->fresh()->status);

        // Luồng tạo link phần còn lại: mã mới lưu vào remaining_payos_code
        $order = $order->fresh();
        $order->update(['remaining_payos_code' => 55123401]);
        $remaining = $this->link($order->fresh(), 55123401, 700000);

        $this->hook($remaining, 700000, 700000011)->assertOk();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('processed', $this->tx(700000011)->status);
    }

    public function test_extra_charge_payment_is_recorded(): void
    {
        $order = $this->order(['status' => 'paid', 'extra_charge_amount' => 50000, 'extra_charge_payos_code' => 55123402]);
        $link = $this->link($order->fresh(), 55123402, 50000);

        $this->hook($link, 50000, 700000020)->assertOk();

        $fresh = $order->fresh();
        $this->assertNotNull($fresh->extra_charge_paid_at);
        $this->assertSame('sepay', $fresh->extra_charge_payment_method);
        $this->assertSame('processed', $this->tx(700000020)->status);
    }

    // ── Webhook: từ chối (tiền đã vào nhưng đơn không đổi) ──────────────────

    public function test_wrong_amount_is_rejected_and_the_order_is_untouched(): void
    {
        $order = $this->order();
        $link = $this->link($order);

        $this->hook($link, 999999, 700000030)->assertOk();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('pending', $link->fresh()->status);
        $this->assertSame('rejected', $this->tx(700000030)->status);
        $this->assertStringContainsString('không khớp', $this->tx(700000030)->failure_reason);
    }

    public function test_a_second_transfer_to_an_already_paid_link_is_rejected(): void
    {
        $order = $this->order();
        $link = $this->link($order);
        $this->hook($link, 1000000, 700000040)->assertOk();

        $this->hook($link, 1000000, 700000041)->assertOk();

        $this->assertSame('rejected', $this->tx(700000041)->status);
        $this->assertStringContainsString('chuyển trùng', $this->tx(700000041)->failure_reason);
    }

    public function test_orders_that_expired_or_were_cancelled_do_not_become_paid(): void
    {
        foreach (['failed', 'cancelled_payment', 'refunded'] as $i => $status) {
            $order = $this->order();
            $link = $this->link($order);
            $order->update(['status' => $status]);

            $this->hook($link, 1000000, 700000050 + $i)->assertOk();

            $this->assertSame($status, $order->fresh()->status, "đơn {$status} phải giữ nguyên");
            $this->assertSame('rejected', $this->tx(700000050 + $i)->status);
            $this->assertStringContainsString($status, $this->tx(700000050 + $i)->failure_reason);
        }
    }

    public function test_a_cancelled_link_is_rejected(): void
    {
        $order = $this->order();
        $link = $this->link($order);
        PayOsAccountResolver::forOrder($order)->cancelPaymentLink($order->order_code);

        $this->hook($link, 1000000, 700000060)->assertOk();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertStringContainsString('đã bị huỷ', $this->tx(700000060)->failure_reason);
    }

    public function test_money_to_another_partners_account_is_rejected(): void
    {
        $other = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->where('id', '!=', $this->partner->id)->first();
        if (! $other) {
            $this->markTestSkipped('Cần ít nhất 2 đối tác Homestay thường.');
        }
        $this->account($other->id, '9999000011112');

        $order = $this->order();
        $link = $this->link($order);

        $this->hook($link, 1000000, 700000070, '9999000011112')->assertOk();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertStringContainsString('không thuộc đối tác của đơn', $this->tx(700000070)->failure_reason);
    }

    public function test_unknown_account_inactive_account_and_unknown_code_are_logged_as_rejected(): void
    {
        $order = $this->order();
        $link = $this->link($order);

        $this->hook($link, 1000000, 700000080, '0000000000')->assertOk();
        $this->assertStringContainsString('chưa được khai báo', $this->tx(700000080)->failure_reason);

        PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->update(['is_active' => false]);
        $this->hook($link, 1000000, 700000081)->assertOk();
        $this->assertStringContainsString('đang tắt', $this->tx(700000081)->failure_reason);
        PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->update(['is_active' => true]);

        $this->send('DH99999999', 1000000, 700000082, self::ACCOUNT, null)->assertOk();
        $this->assertStringContainsString('Không tìm thấy yêu cầu thanh toán', $this->tx(700000082)->failure_reason);
        $this->assertSame('pending', $order->fresh()->status);
    }

    // ── Bảo mật / SePay kiểm tra ────────────────────────────────────────────

    public function test_bad_signature_is_unauthorized_and_the_test_ping_is_acknowledged_without_storing(): void
    {
        $this->call('POST', '/api/webhook/sepay', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SEPAY_SIGNATURE' => 'sha256=bad', 'HTTP_X_SEPAY_TIMESTAMP' => (string) now()->timestamp,
        ], '{}')->assertStatus(401);

        $this->send('DH123456', 1000, 0, self::ACCOUNT, null)->assertOk()->assertJson(['success' => true]);
        $this->assertNull($this->tx(0));
    }

    // ── Trang QR + dữ liệu cho app ──────────────────────────────────────────

    public function test_checkout_page_and_public_payment_data(): void
    {
        $order = $this->order();
        $link = $this->link($order);

        $this->get(route('sepay.checkout', ['code' => $link->code]))->assertOk()->assertSee('DH' . $link->code)->assertSee('1.000.000');

        $data = $this->getJson(route('api.sepay.payments.show', ['code' => $link->code]))->assertOk()->json('data');
        $this->assertSame('pending', $data['status']);
        $this->assertSame(1000000, $data['amount']);
        $this->assertSame(self::ACCOUNT, $data['bank']['account_number']);

        $this->getJson(route('api.sepay.orders.payment', ['code' => $order->order_code]))->assertOk()->assertJsonPath('data.payment_code', 'DH' . $link->code);

        $this->hook($link, 1000000, 700000090)->assertOk();
        $this->getJson(route('api.sepay.payments.show', ['code' => $link->code]))->assertJsonPath('data.status', 'paid')->assertJsonPath('data.order_status', 'paid');
        $this->getJson(route('api.sepay.orders.payment', ['code' => $order->order_code]))->assertStatus(404);
    }

    // ── Lệnh giả lập để thử ở local ─────────────────────────────────────────

    public function test_simulate_command_confirms_the_order_like_a_real_webhook(): void
    {
        $order = $this->order();
        $this->link($order);

        $this->artisan('sepay:simulate', ['code' => $order->order_code, '--amount' => 1])->assertSuccessful();
        $this->assertSame('pending', $order->fresh()->status, 'sai số tiền không xác nhận đơn');

        $this->artisan('sepay:simulate', ['code' => $order->order_code])->assertSuccessful();
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_vietinbank_accounts_get_the_sevqr_prefix_in_the_transfer_content_and_still_confirm(): void
    {
        PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->update(['bank_code' => 'CTG', 'bank_bin' => '970415']);
        $order = $this->order();
        $link = $this->link($order);

        $data = $this->getJson(route('api.sepay.payments.show', ['code' => $link->code]))->assertOk()->json('data');
        $this->assertSame('SEVQR ' . $link->paymentCode(), $data['transfer_content']);
        $this->assertSame($link->paymentCode(), $data['payment_code']);
        $this->assertStringContainsString('des=SEVQR%20' . $link->paymentCode(), $data['qr_url']);
        $this->get(route('sepay.checkout', ['code' => $link->code]))->assertOk()->assertSee('SEVQR ' . $link->paymentCode());

        // Nội dung ngân hàng ghi có SEVQR phía trước: vẫn tách đúng mã
        $this->send($link->paymentCode(), 1000000, 700000300, self::ACCOUNT, 'SEVQR ' . $link->paymentCode() . ' FT25301')->assertOk();
        $this->assertSame('paid', $order->fresh()->status);

        // Ngân hàng khác không thêm tiền tố
        PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->update(['bank_code' => 'MB', 'bank_bin' => '970422']);
        $other = $this->link($this->order());
        $this->assertSame($other->paymentCode(), $this->getJson(route('api.sepay.payments.show', ['code' => $other->code]))->json('data.transfer_content'));
    }
}

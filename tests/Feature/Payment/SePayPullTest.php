<?php

namespace Tests\Feature\Payment;

use App\Models\Partner;
use App\Services\Payment\PayOsAccountResolver;
use App\Services\Payment\SePayService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\PartnerSePayAccount;
use Modules\Payment\Entities\SePayPaymentLink;
use Modules\Payment\Entities\SePayTransaction;
use Tests\TestCase;

// Xác nhận đơn KHÔNG cần webhook: hệ thống hỏi API SePay (trang QR, lệnh sepay:sync) và xử lý đúng như webhook; cùng một giao dịch
// đến bằng cả hai đường chỉ được xử lý một lần; dữ liệu cũ (mã dài) vẫn nhận được.
class SePayPullTest extends TestCase
{
    use DatabaseTransactions;

    private const ACCOUNT = '0011001234567';

    private Partner $partner;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Settings\SePaySettings::fake(['enabled' => null, 'webhook_secret' => null, 'code_prefix' => null, 'api_token' => null, 'min_amount' => null]); // tách khỏi cấu hình CMS thật
        Cache::flush();
        config(['sepay.webhook_secret' => 'flow-secret', 'sepay.code_prefix' => 'DH', 'sepay.enabled' => true, 'sepay.api_token' => 'api-token-xyz', 'sepay.api_url' => 'https://my.sepay.vn']);

        $this->partner = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->firstOrFail();
        $this->partner->forceFill(['payment_flow_effective_at' => now()->subDay()])->save();
        $this->branchId = Category::withoutGlobalScopes()->forceCreate(['name' => 'CN pull ' . Str::random(4), 'slug' => 'cn-pull-' . Str::random(6), 'category_type' => 'product', 'status' => 1, 'partner_id' => $this->partner->id])->id;

        PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->delete();
        PartnerSePayAccount::query()->where('account_number', self::ACCOUNT)->delete();
        PartnerSePayAccount::query()->create(['partner_id' => $this->partner->id, 'is_active' => true, 'bank_code' => 'VCB', 'bank_bin' => '970436', 'account_number' => self::ACCOUNT, 'account_holder' => 'T']);
    }

    private function order(int $amount = 1000000): Order
    {
        return Order::withoutGlobalScopes()->forceCreate([
            'partner_id' => $this->partner->id, 'category_id' => $this->branchId, 'status' => 'pending', 'amount' => $amount, 'full_amount' => $amount,
            'buyer_name' => 'Khach', 'buyer_phone' => '0900000000', 'payment_method' => 'PayOS', 'order_status' => 'pending',
        ]);
    }

    private function link(Order $order): SePayPaymentLink
    {
        $res = PayOsAccountResolver::forOrder($order)->createPaymentLink(['orderCode' => (int) $order->order_code, 'amount' => $order->depositDueAmount(), 'expiredAt' => now()->addMinutes(15)->timestamp]);

        return SePayPaymentLink::query()->where('order_id', $order->id)->where('ref_code', $res['orderCode'])->firstOrFail();
    }

    /** SePay API trả danh sách giao dịch (số tiền là chuỗi như thật). */
    private function fakeApi(array $transactions, int $status = 200): void
    {
        Http::fake(['my.sepay.vn/*' => Http::response(['status' => $status, 'error' => null, 'messages' => ['success' => true], 'transactions' => $transactions], $status)]);
    }

    /** @param list<array<int,array<string,mixed>>> $calls  danh sách giao dịch SePay trả về ở từng lần gọi, theo thứ tự */
    private function fakeApiSequence(array $calls): void
    {
        $sequence = Http::sequence();
        foreach ($calls as $transactions) {
            $sequence->push(['status' => 200, 'error' => null, 'messages' => ['success' => true], 'transactions' => $transactions], 200);
        }
        Http::fake(['my.sepay.vn/*' => $sequence]);
    }

    private function tx(int $id, SePayPaymentLink $link, string $amount = '1000000.00', ?string $content = null): array
    {
        return [
            'id' => (string) $id, 'bank_brand_name' => 'MBBank', 'account_number' => self::ACCOUNT, 'transaction_date' => now()->format('Y-m-d H:i:s'),
            'amount_out' => '0.00', 'amount_in' => $amount, 'accumulated' => '5000000.00',
            'transaction_content' => $content ?? ('NGUYEN VAN A chuyen khoan ' . $link->paymentCode() . ' FT25301'), 'reference_number' => 'FT25301', 'code' => null, 'sub_account' => null, 'bank_account_id' => '19',
        ];
    }

    private function webhook(int $id, string $code, int $amount, ?string $content = null)
    {
        $body = json_encode(['id' => $id, 'gateway' => 'MBBank', 'transactionDate' => now()->format('Y-m-d H:i:s'), 'accountNumber' => self::ACCOUNT, 'code' => $code, 'content' => $content ?? $code, 'transferType' => 'in', 'transferAmount' => $amount, 'accumulated' => 0, 'referenceCode' => 'FT1']);
        $ts = now()->timestamp;

        return $this->call('POST', '/api/webhook/sepay', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SEPAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, 'flow-secret'), 'HTTP_X_SEPAY_TIMESTAMP' => (string) $ts,
        ], $body);
    }

    public function test_pull_confirms_the_order_from_the_sepay_api_without_a_webhook(): void
    {
        $order = $this->order();
        $link = $this->link($order);
        $this->fakeApi([
            ['id' => '9100002', 'account_number' => self::ACCOUNT, 'transaction_date' => now()->format('Y-m-d H:i:s'), 'amount_in' => '50000.00', 'amount_out' => '0.00', 'transaction_content' => 'tien an trua', 'reference_number' => 'FT9', 'code' => null],
            $this->tx(9100001, $link),
        ]);

        $this->assertSame(1, app(SePayService::class)->pull($link));

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('paid', $link->fresh()->status);
        $this->assertSame('processed', SePayTransaction::query()->where('sepay_id', 9100001)->value('status'));
        $this->assertNull(SePayTransaction::query()->where('sepay_id', 9100002)->first(), 'giao dịch không có mã thanh toán là tiền không liên quan, không ghi nhật ký');

        Http::assertSent(function (HttpRequest $r) use ($link) {
            return str_starts_with($r->url(), 'https://my.sepay.vn/userapi/transactions/list')
                && $r->hasHeader('Authorization', 'Bearer api-token-xyz')
                && ! isset($r['account_number']) && (int) $r['amount_in'] === (int) $link->amount;
        });
    }

    public function test_the_qr_page_poll_confirms_the_order_immediately_after_the_transfer(): void
    {
        $order = $this->order();
        $link = $this->link($order);
        $order2 = $this->order();
        $link2 = $this->link($order2);
        // Lần hỏi 1: chưa có tiền. Lần hỏi 2: khách đã chuyển khoản. Lần hỏi 3 (đơn khác, hỏi theo mã đơn): đã chuyển khoản.
        $this->fakeApiSequence([[], [$this->tx(9100010, $link)], [$this->tx(9100011, $link2)]]);

        $this->getJson(route('api.sepay.payments.show', ['code' => $link->code]))->assertOk()->assertJsonPath('data.status', 'pending');

        // Khách chuyển khoản → lần hỏi kế tiếp của trang QR thấy giao dịch (sau khoảng chờ chống gọi dồn)
        Cache::flush();
        $this->getJson(route('api.sepay.payments.show', ['code' => $link->code]))
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.order_status', 'paid');
        $this->assertSame('paid', $order->fresh()->status);

        // App hỏi theo mã đơn khi còn yêu cầu chờ cũng kích hoạt kiểm tra
        $this->getJson(route('api.sepay.orders.payment', ['code' => $order2->order_code]))->assertOk()->assertJsonPath('data.order_status', 'paid');
    }

    public function test_pull_is_throttled_per_payment_request(): void
    {
        $link = $this->link($this->order());
        $this->fakeApi([]);

        app(SePayService::class)->pull($link);
        app(SePayService::class)->pull($link);

        Http::assertSentCount(1);
    }

    public function test_without_an_api_token_nothing_is_called_and_the_webhook_remains_the_only_path(): void
    {
        config(['sepay.api_token' => null]);
        $order = $this->order();
        $link = $this->link($order);
        Http::fake();

        $this->assertSame(0, app(SePayService::class)->pull($link));
        $this->getJson(route('api.sepay.payments.show', ['code' => $link->code]))->assertOk()->assertJsonPath('data.status', 'pending');

        Http::assertNothingSent();
        $this->artisan('sepay:sync')->assertFailed();
    }

    public function test_wrong_amount_found_by_pull_is_logged_as_rejected_and_the_order_is_untouched(): void
    {
        $order = $this->order();
        $link = $this->link($order);
        $this->fakeApi([$this->tx(9100020, $link, '999999.00')]);

        $this->artisan('sepay:sync')->assertSuccessful();

        $this->assertSame('pending', $order->fresh()->status);
        $row = SePayTransaction::query()->where('sepay_id', 9100020)->firstOrFail();
        $this->assertSame('rejected', $row->status);
        $this->assertStringContainsString('không khớp', $row->failure_reason);
    }

    public function test_sync_command_confirms_pending_orders_and_does_nothing_twice(): void
    {
        $a = $this->order();
        $b = $this->order(500000);
        $la = $this->link($a);
        $lb = $this->link($b);
        $this->fakeApi([$this->tx(9100031, $la), $this->tx(9100032, $lb, '500000.00')]);

        $this->artisan('sepay:sync')->assertSuccessful()->expectsOutputToContain('Đã xác nhận 2 đơn');
        $this->assertSame('paid', $a->fresh()->status);
        $this->assertSame('paid', $b->fresh()->status);

        $this->artisan('sepay:sync')->assertSuccessful()->expectsOutputToContain('Đã xác nhận 0 đơn');
        $this->assertSame(1, SePayTransaction::query()->where('sepay_id', 9100031)->count());
    }

    public function test_the_same_transfer_arriving_by_webhook_and_by_pull_is_processed_once(): void
    {
        $order = $this->order();
        $link = $this->link($order);

        $this->webhook(9100040, $link->paymentCode(), 1000000)->assertOk();
        $this->assertSame('paid', $order->fresh()->status);

        $this->fakeApi([$this->tx(9100040, $link)]);
        $this->assertSame(0, app(SePayService::class)->pull());
        $this->assertSame(1, SePayTransaction::query()->where('sepay_id', 9100040)->count());
        $this->assertSame('processed', SePayTransaction::query()->where('sepay_id', 9100040)->value('status'));
    }

    public function test_sepay_api_errors_never_break_anything(): void
    {
        $order = $this->order();
        $link = $this->link($order);
        $calls = 0;
        Http::fake(['my.sepay.vn/*' => function () use (&$calls) {
            $calls++;

            if ($calls === 3) {
                throw new \Illuminate\Http\Client\ConnectionException('timeout');
            }

            return Http::response(['status' => $calls === 1 ? 401 : 500], $calls === 1 ? 401 : 500);
        }]);

        foreach ([1, 2, 3] as $attempt) {
            Cache::flush();
            $this->assertSame(0, app(SePayService::class)->pull($link), "lần {$attempt}: lỗi API không được làm hỏng gì");
        }

        Cache::flush();
        Http::fake(['my.sepay.vn/*' => Http::response(['status' => 401], 401)]);
        $this->getJson(route('api.sepay.payments.show', ['code' => $link->code]))->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_legacy_links_with_a_long_code_still_confirm(): void
    {
        $order = $this->order();
        $legacy = (int) $order->order_code;
        $longCode = 99887766554433; // mã dài kiểu cũ (14 chữ số) từ các yêu cầu tạo trước khi đổi sang mã ngắn
        $link = SePayPaymentLink::query()->create(['order_id' => $order->id, 'code' => $longCode, 'ref_code' => $legacy, 'amount' => 1000000, 'status' => 'pending', 'expires_at' => now()->addMinutes(10)]);
        $order->update(['current_payos_code' => $legacy]);

        $this->webhook(9100050, '', 1000000, 'CHUYEN TIEN DH' . $longCode)->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('paid', $link->fresh()->status);
    }

    public function test_the_payment_code_is_8_digits_and_the_extractor_reads_it_from_bank_content(): void
    {
        $order = $this->order();
        $link = $this->link($order);
        $this->assertMatchesRegularExpression('/^DH\d{8}$/', $link->paymentCode());

        $sePay = app(SePayService::class);
        $this->assertSame((int) $link->code, $sePay->extractCode(['code' => null, 'content' => 'NGUYEN VAN A ' . $link->paymentCode() . 'FT25301']), 'ngân hàng có thể dán thêm mã giao dịch liền sau');
        $this->assertSame((int) $link->code, $sePay->extractCode(['code' => strtolower($link->paymentCode()), 'content' => '']));
        $this->assertNull($sePay->extractCode(['code' => null, 'content' => 'chuyen tien an trua']));
    }

    // ── Tài khoản DOANH NGHIỆP nhận tiền qua tài khoản ảo (VA): SePay báo số chính ở accountNumber và số VA ở subAccount ──

    private function webhookRaw(int $id, string $account, ?string $sub, string $code, int $amount)
    {
        $body = json_encode(['id' => $id, 'gateway' => 'BIDV', 'transactionDate' => now()->format('Y-m-d H:i:s'), 'accountNumber' => $account, 'subAccount' => $sub, 'code' => $code, 'content' => 'CK ' . $code, 'transferType' => 'in', 'transferAmount' => $amount, 'accumulated' => 0, 'referenceCode' => 'FT1']);
        $ts = now()->timestamp;

        return $this->call('POST', '/api/webhook/sepay', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SEPAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, 'flow-secret'), 'HTTP_X_SEPAY_TIMESTAMP' => (string) $ts,
        ], $body);
    }

    public function test_money_arriving_through_a_virtual_account_confirms_the_order_when_cms_holds_the_va_number(): void
    {
        // CMS lưu SỐ VA (số khách chuyển tới); SePay báo số tài khoản chính ở accountNumber và số VA ở subAccount.
        PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->update(['account_number' => 'VA9988776655']);
        $order = $this->order();
        $link = $this->link($order);

        $this->webhookRaw(9100100, '2142000999', 'VA9988776655', $link->paymentCode(), 1000000)->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('processed', SePayTransaction::query()->where('sepay_id', 9100100)->value('status'));
    }

    public function test_money_arriving_to_the_main_account_still_confirms_when_cms_holds_the_main_number(): void
    {
        PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->update(['account_number' => '2142000999']);
        $order = $this->order();
        $link = $this->link($order);

        // SePay có kèm subAccount (VA khác, không khai báo ở CMS) nhưng số chính đã khai báo
        $this->webhookRaw(9100101, '2142000999', 'VA0000000000', $link->paymentCode(), 1000000)->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_an_unregistered_account_or_va_is_still_rejected_with_a_clear_reason(): void
    {
        $order = $this->order();
        $link = $this->link($order);

        $this->webhookRaw(9100102, '1111111111', 'VA2222222222', $link->paymentCode(), 1000000)->assertOk();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertStringContainsString('chưa được khai báo', SePayTransaction::query()->where('sepay_id', 9100102)->value('failure_reason'));
    }

    public function test_pull_sees_va_transactions_and_confirms_them(): void
    {
        PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->update(['account_number' => 'VA9988776655']);
        $order = $this->order();
        $link = $this->link($order);

        $this->fakeApi([[
            'id' => '9100110', 'account_number' => '2142000999', 'sub_account' => 'VA9988776655', 'transaction_date' => now()->format('Y-m-d H:i:s'),
            'amount_in' => '1000000.00', 'amount_out' => '0.00', 'transaction_content' => 'CK ' . $link->paymentCode(), 'reference_number' => 'FT9', 'code' => null,
        ]]);

        $this->artisan('sepay:sync')->assertSuccessful()->expectsOutputToContain('Đã xác nhận 1 đơn');
        $this->assertSame('paid', $order->fresh()->status);
    }
}

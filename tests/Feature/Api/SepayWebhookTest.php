<?php

namespace Tests\Feature\Api;

use App\Models\Partner;
use App\Models\User;
use App\Services\Payment\SepayWebhookService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\BankTransaction;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\PartnerBankChannel;
use Tests\TestCase;

// KÊNH CHUYỂN KHOẢN (VietQR + SePay): webhook chỉ ghi nhận tiền vào đúng tài khoản của kênh, cho đơn của chính đối tác đó, đủ số
// tiền và đúng 1 lần; mọi khoản không tự ghi nhận được vẫn lưu lại để đối soát mà không đổi trạng thái đơn.
class SepayWebhookTest extends TestCase
{
    use DatabaseTransactions;

    private Partner $partner;

    private Category $branch;

    private array $adminHeaders;

    private string $key;

    private static int $txnId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        config(['sepay.webhook_key' => null, 'sepay.payment_prefix' => '365H']);

        foreach ([\App\Services\TelegramService::class, \Modules\BladeThemeV1\Services\AccessCode\AccessCodeService::class, \App\Services\NotificationFcmService::class,
            \App\Services\OrderRealtimeService::class, \App\Services\FcmService::class, \App\Services\AdminNotificationRealtimeService::class] as $service) {
            if (class_exists($service)) {
                $this->mock($service)->shouldIgnoreMissing();
            }
        }

        $this->partner = $this->makePartner('Homestay Chuyển Khoản', '0970000901');
        $this->branch = $this->makeBranch($this->partner);

        $admin = User::create(['fullname' => 'Super Admin SePay', 'email' => 'sepay-admin@example.test', 'password' => 'secret-secret']);
        $admin->assignRole(config('filament-shield.super_admin.name'));
        $this->adminHeaders = ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

        $this->key = $this->postJson("/api/admin/partners/{$this->partner->id}/bank-channel", [
            'bank_code' => 'MB', 'account_number' => '0123456789', 'account_holder' => 'Nguyễn Văn An',
        ], $this->adminHeaders)->assertOk()->json('data.webhook_key');
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function makePartner(string $name, string $phone): Partner
    {
        // payment_flow_effective_at: hợp đồng thu trực tiếp đã hiệu lực — kênh riêng của đối tác chỉ dùng được từ mốc này.
        $partner = Partner::create([
            'partner_type' => Partner::TYPE_HOMESTAY, 'name' => $name, 'legal_name' => $name, 'phone' => $phone,
            'status' => true, 'verification_status' => 'approved', 'contract_status' => 'active',
        ]);
        $partner->forceFill(['payment_flow_effective_at' => now()->subDay()])->save();

        return $partner;
    }

    private function makeBranch(Partner $partner): Category
    {
        return Category::create(['name' => $name = 'Chi nhanh sepay ' . uniqid(), 'slug' => Str::slug($name), 'category_type' => 'product', 'parent_id' => null, 'partner_id' => $partner->id, 'status' => true]);
    }

    private function order(array $attributes = [], ?Category $branch = null): Order
    {
        return Order::create(array_merge([
            'amount' => 500000, 'full_amount' => 500000, 'description' => 'Đơn test SePay', 'buyer_name' => 'Khách',
            'buyer_phone' => '09' . random_int(10000000, 99999999), 'payment_method' => 'PayOS', 'status' => 'pending', 'guest_count' => 1,
            'category_id' => ($branch ?? $this->branch)->id, 'partner_id' => ($branch ?? $this->branch)->partner_id,
        ], $attributes));
    }

    private function webhook(array $override = [], ?string $key = null)
    {
        $payload = $override + [
            'id' => 'test-' . uniqid() . '-' . ++self::$txnId, 'gateway' => 'MBBank', 'transactionDate' => now()->format('Y-m-d H:i:s'),
            'accountNumber' => '0123456789', 'code' => null, 'content' => '', 'transferType' => 'in', 'transferAmount' => 500000,
            'accumulated' => 0, 'subAccount' => null, 'referenceCode' => 'FT' . random_int(100000, 999999), 'description' => '',
        ];

        return $this->postJson('/api/webhook/sepay', $payload, ['Authorization' => 'Apikey ' . ($key ?? $this->key)]);
    }

    public function test_channel_setup_returns_key_only_to_super_admin_and_marks_orders_collected_by_partner(): void
    {
        $this->assertSame(48, strlen($this->key));
        $this->assertDatabaseMissing('partner_bank_channels', ['webhook_key' => $this->key]);
        $this->getJson("/api/admin/partners/{$this->partner->id}/bank-channel", $this->adminHeaders)->assertOk()
            ->assertJsonPath('data.in_use', true)->assertJsonPath('data.bank_name', 'MB Bank')->assertJsonPath('data.account_holder', 'NGUYEN VAN AN');

        $staff = User::create(['fullname' => 'Nhân viên', 'email' => 'sepay-staff@example.test', 'password' => 'secret-secret', 'partner_id' => $this->partner->id]);
        $staffHeaders = ['Authorization' => 'Bearer ' . $staff->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
        $this->getJson("/api/admin/partners/{$this->partner->id}/bank-channel", $staffHeaders)->assertOk()->assertJsonPath('data.webhook_key', null);
        $this->postJson("/api/admin/partners/{$this->partner->id}/bank-channel", ['is_active' => false], $staffHeaders)->assertForbidden();

        $this->assertSame('partner', $this->order()->fresh()->collected_by);
    }

    public function test_transfer_info_is_only_for_the_order_owner_and_builds_vietqr_for_the_amount_due(): void
    {
        $order = $this->order(['deposit_percent' => 30]);
        $url = "/api/payment/bank-transfer/{$order->order_code}";

        // Không phải chủ đơn (thiếu/sai SĐT) → 404 như đơn không tồn tại.
        $this->getJson($url)->assertNotFound();
        $this->getJson($url . '?phone=0999999999')->assertNotFound();

        $response = $this->getJson($url . '?phone=' . $order->buyer_phone)->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('data.amount', 150000)
            ->assertJsonPath('data.content', '365H' . $order->order_code)
            ->assertJsonPath('data.account_number', '0123456789')
            ->assertJsonPath('data.bank_bin', '970422');
        $this->assertSame(\App\Support\VietQr::payload('970422', '0123456789', 150000, '365H' . $order->order_code), $response->json('data.qr_payload'));
        $this->assertStringContainsString('signature=', $response->json('data.page_url'));

        // Đối tác không có kênh chuyển khoản: app dùng PayOS như cũ.
        $otherOrder = $this->order([], $this->makeBranch($this->makePartner('Đối tác PayOS', '0970000902')));
        $this->getJson("/api/payment/bank-transfer/{$otherOrder->order_code}?phone={$otherOrder->buyer_phone}")->assertOk()->assertJsonPath('available', false);
    }

    public function test_bank_transfer_page_needs_a_valid_signed_link_and_is_not_cacheable(): void
    {
        $order = $this->order();
        $pageUrl = SepayWebhookService::pageUrl($order);

        // Đoán mã đơn / sửa chữ ký / link hết hạn đều bị chặn.
        $this->get("/chuyen-khoan/{$order->order_code}")->assertForbidden();
        $this->get(preg_replace('/signature=[^&]+/', 'signature=sai', $pageUrl))->assertForbidden();

        $page = $this->get($pageUrl)->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertSee('365H' . $order->order_code)
            ->assertDontSee('img.vietqr.io')
            ->assertDontSee($order->buyer_phone);
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));

        $this->get(SepayWebhookService::statusUrl($order))->assertOk()->assertExactJson(['status' => 'pending']);
        $this->get("/chuyen-khoan/{$order->order_code}/trang-thai")->assertForbidden();

        $this->travel(config('sepay.page_ttl_minutes', 120) + 1)->minutes();
        $this->get($pageUrl)->assertForbidden();
    }

    public function test_vietqr_payload_follows_napas_format(): void
    {
        $this->assertSame('29B1', \App\Support\VietQr::crc16('123456789'));
        $this->assertSame(
            '00020101021238520010A000000727012200069704160108273043770208QRIBFTTA5303704540466005802VN62220818365H1793168213555363048651',
            \App\Support\VietQr::payload('970416', '27304377', 6600, '365H17931682135553')
        );
    }

    public function test_webhook_can_be_limited_to_sepay_ips(): void
    {
        config(['sepay.allowed_ips' => '103.255.238.0/24']);
        $this->webhook()->assertForbidden();

        config(['sepay.allowed_ips' => '127.0.0.1']);
        $this->webhook(['content' => 'khong ma'])->assertOk();
    }

    public function test_full_payment_marks_order_paid_once_even_when_sepay_retries(): void
    {
        $order = $this->order();
        $payload = ['id' => 'retry-' . uniqid(), 'content' => 'MBVCB.123 365H ' . $order->order_code . ' chuyen tien'];

        $this->webhook($payload)->assertOk()->assertJsonPath('success', true)->assertJsonPath('status', 'matched');
        $this->assertSame('paid', $order->fresh()->status);

        $this->webhook($payload)->assertOk()->assertJsonPath('status', 'matched');
        $this->assertSame(1, BankTransaction::where('provider_txn_id', $payload['id'])->count());
    }

    public function test_deposit_then_remaining_and_full_transfer_on_deposit_order(): void
    {
        $order = $this->order(['deposit_percent' => 30]);

        $this->webhook(['content' => '365H' . $order->order_code, 'transferAmount' => 150000])->assertJsonPath('status', 'matched');
        $this->assertSame('deposit', $order->fresh()->status);

        $this->webhook(['content' => '365H' . $order->order_code, 'transferAmount' => 350000])->assertJsonPath('status', 'matched');
        $this->assertSame('paid', $order->fresh()->status);

        // Khách chuyển luôn đủ cả đơn cọc: ghi nhận cả 2 phần.
        $second = $this->order(['deposit_percent' => 30]);
        $this->webhook(['content' => '365H' . $second->order_code, 'transferAmount' => 500000])->assertJsonPath('status', 'matched');
        $this->assertSame('paid', $second->fresh()->status);
    }

    public function test_short_unknown_and_late_transfers_are_kept_for_review_without_touching_orders(): void
    {
        $order = $this->order();

        $this->webhook(['content' => '365H' . $order->order_code, 'transferAmount' => 400000])->assertOk()->assertJsonPath('status', BankTransaction::STATUS_AMOUNT_MISMATCH);
        $this->webhook(['content' => 'chuyen tien phong'])->assertOk()->assertJsonPath('status', BankTransaction::STATUS_UNMATCHED);
        $this->assertSame('pending', $order->fresh()->status);

        $expired = $this->order(['status' => 'cancelled_payment']);
        $this->webhook(['content' => '365H' . $expired->order_code])->assertOk()->assertJsonPath('status', BankTransaction::STATUS_NEEDS_REVIEW);
        $this->assertSame('cancelled_payment', $expired->fresh()->status);

        // Tiền ra không ghi nhận gì.
        $this->webhook(['content' => '365H' . $order->order_code, 'transferType' => 'out'])->assertOk()->assertJsonPath('status', 'ignored');

        $this->getJson("/api/admin/partners/{$this->partner->id}/bank-transactions?status=amount_mismatch", $this->adminHeaders)->assertOk()
            ->assertJsonPath('data.0.order.order_code', (string) $order->order_code);
    }

    public function test_a_partner_key_cannot_confirm_other_accounts_or_other_partners_orders(): void
    {
        $this->webhook([], 'sai-khoa')->assertUnauthorized();
        $this->postJson('/api/webhook/sepay', ['id' => 1, 'transferType' => 'in'])->assertUnauthorized();

        // Đối tác B có kênh riêng; khoá của B gửi tiền "vào tài khoản A" hoặc đánh dấu đơn của A đều không được.
        $otherPartner = $this->makePartner('Đối tác B', '0970000903');
        $otherKey = $this->postJson("/api/admin/partners/{$otherPartner->id}/bank-channel", ['bank_code' => 'ACB', 'account_number' => '999888777'], $this->adminHeaders)->json('data.webhook_key');
        $order = $this->order();

        $this->webhook(['content' => '365H' . $order->order_code], $otherKey)->assertOk()->assertJsonPath('status', BankTransaction::STATUS_UNMATCHED);
        $this->webhook(['content' => '365H' . $order->order_code, 'accountNumber' => '999888777'], $otherKey)->assertOk()->assertJsonPath('status', BankTransaction::STATUS_UNMATCHED);
        $this->assertSame('pending', $order->fresh()->status);

        // Cấp lại khoá: khoá cũ hết hiệu lực.
        $newKey = $this->postJson("/api/admin/partners/{$this->partner->id}/bank-channel/regenerate-key", [], $this->adminHeaders)->json('data.webhook_key');
        $this->webhook(['content' => '365H' . $order->order_code])->assertUnauthorized();
        $this->webhook(['content' => '365H' . $order->order_code], $newKey)->assertJsonPath('status', 'matched');
    }

    public function test_global_key_routes_by_receiving_account(): void
    {
        config(['sepay.webhook_key' => 'khoa-chung-365home']);
        $order = $this->order();

        $this->webhook(['content' => '365H' . $order->order_code], 'khoa-chung-365home')->assertJsonPath('status', 'matched');
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_order_code_extraction_tolerates_bank_formatting(): void
    {
        $service = app(SepayWebhookService::class);

        $this->assertSame('12345678', $service->extractOrderCode(['content' => 'IBFT 365H-12345678 FT2630']));
        $this->assertSame('12345678', $service->extractOrderCode(['code' => '365H12345678', 'content' => 'abc']));
        $this->assertSame('12345678', $service->extractOrderCode(['content' => '365h 12345678']));
        $this->assertNull($service->extractOrderCode(['content' => '36512345678']));
        // Đơn đặt trên web có mã 14 chữ số.
        $this->assertSame('17931682135553', $service->extractOrderCode(['content' => '365H17931682135553 FT26301780100662 GD 6301IBT1k2GL9EII 281026-13:17:39']));
    }

    public function test_replay_reprocesses_a_transaction_that_could_not_be_matched(): void
    {
        $order = $this->order();
        $id = $this->webhook(['content' => 'chuyen tien ' . $order->order_code])->json('status') === BankTransaction::STATUS_UNMATCHED
            ? BankTransaction::latest('id')->value('id') : null;
        $this->assertNotNull($id);

        // Sửa dữ liệu đã lưu như thể đọc được mã đơn, rồi xử lý lại: ghi nhận đúng 1 lần.
        $txn = BankTransaction::find($id);
        $txn->update(['payload' => ['content' => '365H' . $order->order_code] + $txn->payload]);
        $this->artisan('sepay:replay', ['ids' => [$id]])->assertSuccessful();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(BankTransaction::STATUS_MATCHED, BankTransaction::where('provider_txn_id', $txn->provider_txn_id)->value('status'));
    }
}

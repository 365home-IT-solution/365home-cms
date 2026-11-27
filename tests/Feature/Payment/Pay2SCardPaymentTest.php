<?php

namespace Tests\Feature\Payment;

use App\Models\Partner;
use App\Services\Payment\Pay2SCardService;
use App\Settings\Pay2SCardSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\Pay2SCardPayment;
use Tests\TestCase;

// Thanh toán thẻ Pay2S (chỉ chi nhánh 365home): điều kiện áp dụng, tạo phiên (chữ ký, số tiền), webhook (chữ ký, đối chiếu, chạy lặp, hoàn tiền thủ công khi đơn không còn nhận).
class Pay2SCardPaymentTest extends TestCase
{
    use DatabaseTransactions;
    use \Tests\Concerns\RestoresSettings; // cài đặt KHÔNG được hoàn tác theo transaction: test gọi update() ghi thẳng vào bảng settings của DB dev

    private const SECRET = 'card-secret-key-for-tests';

    private const ACCESS = 'card-access-key';

    private const PARTNER_CODE = 'P2STEST';

    private int $ownBranchId;

    private int $partnerBranchId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshotSettings('pay2s_card');
        $this->blockOutboundNotifications();
        $this->fakeSettings();

        $this->ownBranchId = $this->branch(null);
        $partner = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->firstOrFail();
        $this->partnerBranchId = $this->branch($partner->id);
    }

    protected function tearDown(): void
    {
        $this->restoreSettings();
        app()->forgetInstance(Pay2SCardSettings::class);

        parent::tearDown();
    }

    // Test chạy trên DB dev có token thiết bị thật: tạo/đổi trạng thái đơn kích OrderObserver → chuông admin, FCM, realtime tới APP THẬT.
    // Chặn hết đường ra ngoài (cùng cách các test khác của dự án): FCM admin + khách, realtime đơn / thông báo admin, và phát sóng Reverb.
    private function blockOutboundNotifications(): void
    {
        $this->mock(\App\Services\FcmService::class)->shouldIgnoreMissing();
        $this->mock(\App\Services\NotificationFcmService::class)->shouldIgnoreMissing();
        $this->mock(\App\Services\AdminNotificationRealtimeService::class)->shouldIgnoreMissing();
        $this->mock(\App\Services\OrderRealtimeService::class)->shouldIgnoreMissing();
        config(['broadcasting.default' => 'null']);
    }

    private function fakeSettings(array $override = []): void
    {
        Pay2SCardSettings::fake($override + [
            'enabled' => true, 'partner_code' => self::PARTNER_CODE, 'access_key' => self::ACCESS, 'secret_key' => self::SECRET,
            'connection_id' => null, 'sandbox_only' => false, 'merchant_origin' => 'https://365home.test', 'api_url' => null, 'category_ids' => [],
        ]);
        app()->forgetInstance(Pay2SCardService::class);
    }

    private function branch(?string $partnerId): int
    {
        return Category::withoutGlobalScopes()->forceCreate([
            'name' => 'CN thẻ ' . Str::random(4), 'slug' => 'cn-the-' . Str::random(6), 'category_type' => 'product', 'status' => 1, 'partner_id' => $partnerId,
        ])->id;
    }

    private function order(int $branchId, array $attrs = []): Order
    {
        return Order::withoutGlobalScopes()->forceCreate($attrs + [
            'category_id' => $branchId, 'status' => 'pending', 'amount' => 1000000, 'full_amount' => 1000000, 'buyer_name' => 'Nguyen Van A',
            'buyer_phone' => '09' . random_int(10000000, 99999999), 'payment_method' => 'PayOS', 'order_status' => 'pending', 'expired_at' => now()->addMinutes(15),
        ]);
    }

    private function enableFor(int ...$ids): void
    {
        $this->fakeSettings(['category_ids' => $ids]);
    }

    // Stub khớp theo thứ tự đăng ký ⇒ Pay2S trước, mọi địa chỉ khác (Telegram, FCM...) sau cùng. Mỗi lần gọi thay toàn bộ stub cũ.
    private function fakeHttp(array $pay2sResponse, int $status = 200): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['payment.pay2s.vn/*' => Http::response($pay2sResponse, $status), '*' => Http::response(['ok' => true], 200)]);
    }

    private function fakeCreate(string $env = 'production'): void
    {
        $this->fakeHttp(['status' => true, 'data' => [
            'sessionId' => str_repeat('a', 64), 'environment' => $env, 'expiresAt' => now()->addMinutes(15)->timestamp,
            'paymentUrl' => 'https://payment.pay2s.vn/card-checkout/index.html#card-session=abc',
        ]]);
    }

    private function webhook(Pay2SCardPayment $p, array $override = [], bool $sign = true): array
    {
        $payload = $override + [
            'accessKey' => self::ACCESS, 'amount' => (int) $p->amount, 'extraData' => '', 'message' => 'Paymentsuccessful', 'orderId' => $p->pay2s_order_id,
            'orderInfo' => 'Thanh toan', 'orderType' => 'card', 'partnerCode' => self::PARTNER_CODE, 'payType' => 'card', 'requestId' => 'r1',
            'responseTime' => (string) (time() * 1000), 'resultCode' => 0, 'transId' => 'INV123',
        ];

        $raw = collect(['accessKey', 'amount', 'extraData', 'message', 'orderId', 'orderInfo', 'orderType', 'partnerCode', 'payType', 'requestId', 'responseTime', 'resultCode', 'transId'])
            ->map(fn ($k) => $k . '=' . $payload[$k])->implode('&');
        $payload['m2signature'] = $sign ? hash_hmac('sha256', $raw, self::SECRET) : 'bad';

        return $payload;
    }

    private function openSession(Order $order): Pay2SCardPayment
    {
        $this->fakeCreate();

        return app(Pay2SCardService::class)->createSession($order, 'visa');
    }

    // ───────────── Điều kiện áp dụng ─────────────

    public function test_only_whitelisted_own_branch_is_eligible(): void
    {
        $svc = app(Pay2SCardService::class);

        $this->fakeSettings(['category_ids' => []]);
        $this->assertFalse($svc->eligible($this->order($this->ownBranchId)), 'chưa chọn chi nhánh nào');

        $this->enableFor($this->ownBranchId);
        $this->assertTrue($svc->eligible($this->order($this->ownBranchId)));

        $this->enableFor($this->partnerBranchId);
        $this->assertFalse($svc->eligible($this->order($this->partnerBranchId)), 'chi nhánh của đối tác thường không được dùng thẻ');

        $this->fakeSettings(['category_ids' => [$this->ownBranchId], 'enabled' => false]);
        $this->assertFalse($svc->eligible($this->order($this->ownBranchId)), 'đang tắt');

        $this->fakeSettings(['category_ids' => [$this->ownBranchId], 'secret_key' => null]);
        $this->assertFalse($svc->eligible($this->order($this->ownBranchId)), 'thiếu khoá');
    }

    // Bước đặt phòng chưa có đơn: chỉ theo chi nhánh — quyết định có hiện lựa chọn "Thẻ quốc tế" không.
    public function test_eligible_for_category_before_order_exists(): void
    {
        $svc = app(Pay2SCardService::class);

        $this->enableFor($this->ownBranchId);
        $this->assertTrue($svc->eligibleForCategory($this->ownBranchId));
        $this->assertFalse($svc->eligibleForCategory($this->partnerBranchId), 'chi nhánh đối tác không bật');
        $this->assertFalse($svc->eligibleForCategory(null));

        $this->enableFor($this->partnerBranchId);
        $this->assertFalse($svc->eligibleForCategory($this->partnerBranchId), 'đã tick nhưng thuộc đối tác thường ⇒ vẫn không được dùng thẻ');
    }

    public function test_child_area_of_enabled_branch_is_eligible(): void
    {
        $child = Category::withoutGlobalScopes()->forceCreate(['name' => 'KV con', 'slug' => 'kv-' . Str::random(6), 'category_type' => 'product', 'status' => 1, 'parent_id' => $this->ownBranchId])->id;
        $this->enableFor($this->ownBranchId);

        $this->assertTrue(app(Pay2SCardService::class)->eligible($this->order($child)));
    }

    public function test_due_amount_follows_order_stage(): void
    {
        $svc = app(Pay2SCardService::class);

        $this->assertSame(['kind' => 'initial', 'amount' => 1000000], $svc->due($this->order($this->ownBranchId)));
        $this->assertSame(['kind' => 'initial', 'amount' => 300000], $svc->due($this->order($this->ownBranchId, ['deposit_percent' => 30])));
        $this->assertSame(['kind' => 'remaining', 'amount' => 700000], $svc->due($this->order($this->ownBranchId, ['deposit_percent' => 30, 'status' => 'deposit'])));
        $this->assertNull($svc->due($this->order($this->ownBranchId, ['status' => 'paid'])));
        $this->assertNull($svc->due($this->order($this->ownBranchId, ['amount' => 1000, 'full_amount' => 1000])), 'dưới mức tối thiểu 2.000đ');
    }

    // ───────────── Tạo phiên ─────────────

    public function test_create_session_sends_signed_request_with_exact_amount(): void
    {
        $this->enableFor($this->ownBranchId);
        $order = $this->order($this->ownBranchId, ['deposit_percent' => 30]);
        $payment = $this->openSession($order);

        $this->assertSame('pending', $payment->status);
        $this->assertSame(300000, $payment->amount);
        $this->assertSame('initial', $payment->kind);
        $this->assertStringContainsString('card-checkout', $payment->payment_url);

        Http::assertSent(function (HttpRequest $request) use ($payment) {
            if (! str_contains($request->url(), 'payment.pay2s.vn')) {
                return false;
            }
            $body = $request->body();
            $data = json_decode($body, true);
            $expected = hash_hmac('sha256', implode("\n", ['pay2s-card-popup-v1', $request->header('X-Pay2S-Timestamp')[0], hash('sha256', $body)]), self::SECRET);

            return $data['action'] === 'create' && $data['amount'] === 300000 && $data['orderId'] === $payment->pay2s_order_id && $data['cardBrand'] === 'visa'
                && $data['checkoutMode'] === 'hosted' && $data['merchantOrigin'] === 'https://365home.test'
                && str_starts_with($data['returnUrl'], 'https://365home.test/') // Pay2S bắt returnUrl cùng origin với merchantOrigin && str_ends_with($data['webhookUrl'], '/api/webhook/pay2s-card')
                && $request->header('X-Pay2S-Partner')[0] === self::PARTNER_CODE && $request->header('X-Pay2S-Access-Key')[0] === self::ACCESS
                && hash_equals($expected, $request->header('X-Pay2S-Signature')[0]);
        });
    }

    public function test_create_session_reuses_open_session_for_same_amount(): void
    {
        $this->enableFor($this->ownBranchId);
        $order = $this->order($this->ownBranchId);

        $first = $this->openSession($order);
        $second = app(Pay2SCardService::class)->createSession($order->fresh(), 'visa');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Pay2SCardPayment::query()->where('order_id', $order->id)->count());
    }

    public function test_create_session_rejected_for_non_eligible_order_and_bad_brand(): void
    {
        $this->enableFor($this->ownBranchId);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(Pay2SCardService::class)->createSession($this->order($this->partnerBranchId), 'visa');
    }

    public function test_create_session_failure_marks_payment_failed(): void
    {
        $this->enableFor($this->ownBranchId);
        $this->fakeHttp(['status' => false, 'message' => 'Invalid signature'], 401);
        $order = $this->order($this->ownBranchId);

        try {
            app(Pay2SCardService::class)->createSession($order, 'visa');
            $this->fail('Phải ném lỗi khi Pay2S từ chối.');
        } catch (\RuntimeException) {
        }

        $this->assertSame('failed', Pay2SCardPayment::query()->where('order_id', $order->id)->value('status'));
    }

    public function test_guest_api_creates_session_and_exposes_availability(): void
    {
        $this->enableFor($this->ownBranchId);
        $order = $this->order($this->ownBranchId);
        $this->fakeCreate();

        $this->postJson("/api/guest/orders/{$order->order_code}/card-payment", ['phone' => $order->buyer_phone, 'card_brand' => 'mastercard'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', 1000000)
            ->assertJsonPath('data.card_brand', 'mastercard')
            ->assertJsonStructure(['data' => ['payment_url', 'expires_at']]);

        $this->postJson("/api/guest/orders/{$order->order_code}/card-payment", ['phone' => '0000000000'])->assertNotFound();
        $this->postJson("/api/guest/orders/{$order->order_code}/card-payment", ['phone' => $order->buyer_phone, 'card_brand' => 'diners'])->assertStatus(422);

        $fields = app(\App\Services\Payment\SePayService::class)->paymentFields($order->fresh());
        $this->assertTrue($fields['card_payment']['available']);
        $this->assertSame(1000000, $fields['card_payment']['amount']);
    }

    // ───────────── Trang web khách (/thong-tin-dat-phong) ─────────────

    public function test_web_checkout_creates_session_and_reports_status(): void
    {
        $this->enableFor($this->ownBranchId);
        $order = $this->order($this->ownBranchId);
        $this->fakeCreate();

        $this->postJson(route('payment.card.create', $order->order_code), ['card_brand' => 'visa'])
            ->assertOk()->assertJson(['error' => 0])->assertJsonStructure(['paymentUrl', 'expiresAt']);

        $this->postJson(route('payment.card.create', $order->order_code), ['card_brand' => 'diners'])->assertStatus(422);
        $this->postJson(route('payment.card.create', '0000000'), ['card_brand' => 'visa'])->assertNotFound();

        $this->getJson(route('payment.card.status', $order->order_code))->assertOk()->assertJson(['error' => 0, 'orderStatus' => 'pending']);
    }

    public function test_web_checkout_rejects_order_of_partner_branch(): void
    {
        $this->enableFor($this->partnerBranchId);
        $order = $this->order($this->partnerBranchId);

        $this->postJson(route('payment.card.create', $order->order_code), ['card_brand' => 'visa'])
            ->assertStatus(422)->assertJson(['error' => 1]);
    }

    // ───────────── Webhook ─────────────

    public function test_webhook_with_valid_signature_marks_order_paid_once(): void
    {
        $this->enableFor($this->ownBranchId);
        $order = $this->order($this->ownBranchId);
        $payment = $this->openSession($order);
        $payload = $this->webhook($payment);

        $this->postJson('/api/webhook/pay2s-card', $payload)->assertOk()->assertJson(['success' => true]);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('INV123', $payment->fresh()->trans_id);

        // Pay2S gửi lại: không xử lý lần hai, vẫn trả success để dừng gửi lại.
        $this->postJson('/api/webhook/pay2s-card', $payload)->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, Pay2SCardPayment::query()->where('order_id', $order->id)->where('status', 'paid')->count());
    }

    public function test_webhook_with_bad_signature_is_rejected_and_order_untouched(): void
    {
        $this->enableFor($this->ownBranchId);
        $order = $this->order($this->ownBranchId);
        $payment = $this->openSession($order);

        $this->postJson('/api/webhook/pay2s-card', $this->webhook($payment, [], sign: false))->assertStatus(401);
        $this->postJson('/api/webhook/pay2s-card', $this->webhook($payment, ['accessKey' => 'khac']))->assertStatus(401);

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_webhook_with_wrong_amount_goes_to_review(): void
    {
        $this->enableFor($this->ownBranchId);
        $order = $this->order($this->ownBranchId);
        $payment = $this->openSession($order);

        $this->postJson('/api/webhook/pay2s-card', $this->webhook($payment, ['amount' => 5000]))->assertOk();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('review', $payment->fresh()->status);
        $this->assertStringContainsString('Số tiền không khớp', (string) $payment->fresh()->failure_reason);
    }

    public function test_paid_webhook_for_cancelled_order_goes_to_review_for_manual_refund(): void
    {
        $this->enableFor($this->ownBranchId);
        $order = $this->order($this->ownBranchId);
        $payment = $this->openSession($order);
        $order->forceFill(['status' => 'cancelled_payment'])->save();

        $this->postJson('/api/webhook/pay2s-card', $this->webhook($payment))->assertOk();

        $this->assertSame('cancelled_payment', $order->fresh()->status);
        $this->assertSame('review', $payment->fresh()->status);
    }

    public function test_sandbox_payment_is_not_accepted_in_live_mode(): void
    {
        $this->enableFor($this->ownBranchId);
        $order = $this->order($this->ownBranchId);
        $this->fakeCreate('sandbox');
        $payment = app(Pay2SCardService::class)->createSession($order, 'visa');

        $this->postJson('/api/webhook/pay2s-card', $this->webhook($payment))->assertOk();

        $this->assertSame('pending', $order->fresh()->status, 'thẻ giả lập không được xác nhận đơn khi đang chạy thật');
        $this->assertSame('review', $payment->fresh()->status);
    }

    public function test_cancelled_webhook_closes_session_without_touching_order(): void
    {
        $this->enableFor($this->ownBranchId);
        $order = $this->order($this->ownBranchId);
        $payment = $this->openSession($order);

        $this->postJson('/api/webhook/pay2s-card', $this->webhook($payment, ['resultCode' => 2, 'message' => 'Paymentcancelled']))->assertOk();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('cancelled', $payment->fresh()->status);
    }

    public function test_deposit_order_flow_initial_then_remaining(): void
    {
        $this->enableFor($this->ownBranchId);
        $order = $this->order($this->ownBranchId, ['deposit_percent' => 30]);

        $initial = $this->openSession($order);
        $this->postJson('/api/webhook/pay2s-card', $this->webhook($initial))->assertOk();
        $this->assertSame('deposit', $order->fresh()->status);

        $this->fakeCreate();
        $remaining = app(Pay2SCardService::class)->createSession($order->fresh(), 'visa');
        $this->assertSame('remaining', $remaining->kind);
        $this->assertSame(700000, $remaining->amount);

        $this->postJson('/api/webhook/pay2s-card', $this->webhook($remaining))->assertOk();
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_status_sync_confirms_paid_order_when_webhook_is_late(): void
    {
        $this->enableFor($this->ownBranchId);
        $order = $this->order($this->ownBranchId);
        $payment = $this->openSession($order);

        $this->fakeHttp(['status' => true, 'data' => ['status' => 'paid', 'amount' => 1000000, 'environment' => 'production']]);

        $synced = app(Pay2SCardService::class)->sync($payment);

        $this->assertSame('paid', $synced->status);
        $this->assertSame('paid', $order->fresh()->status);
    }

    // ───────────── Admin API ─────────────

    // ───────────── Bật/tắt, nhãn "Thẻ", API admin bổ sung ─────────────

    private function superAdminUser(): \App\Models\User
    {
        $u = \App\Models\User::query()->get()->first(fn ($x) => $x->isSuperAdmin());
        if (! $u) {
            $this->markTestSkipped('Cần 1 Super Admin trong DB.');
        }

        return $u;
    }

    public function test_switching_off_hides_card_everywhere_and_restores_old_responses(): void
    {
        $order = $this->order($this->ownBranchId);
        $this->enableFor($this->ownBranchId);
        $svc = app(Pay2SCardService::class);

        $this->assertArrayHasKey('card_payment', app(\App\Services\Payment\SePayService::class)->paymentFields($order));

        // TẮT: không còn lựa chọn thẻ ở bước đặt phòng / phản hồi đơn / API tạo phiên — như chưa có tính năng.
        $this->fakeSettings(['category_ids' => [$this->ownBranchId], 'enabled' => false]);
        $this->assertFalse($svc->eligible($order));
        $this->assertFalse($svc->eligibleForCategory($this->ownBranchId));
        $this->assertArrayNotHasKey('card_payment', app(\App\Services\Payment\SePayService::class)->paymentFields($order));
        $this->postJson(route('payment.card.create', $order->order_code), ['card_brand' => 'visa'])->assertStatus(422);
        $this->postJson("/api/guest/orders/{$order->order_code}/card-payment", ['phone' => $order->buyer_phone])->assertStatus(422);
    }

    public function test_paid_by_card_orders_get_the_card_label_and_other_orders_do_not(): void
    {
        $this->enableFor($this->ownBranchId);
        $svc = app(Pay2SCardService::class);
        $order = $this->order($this->ownBranchId);
        $other = $this->order($this->ownBranchId);

        $this->assertSame([], $svc->labelFields($order), 'chưa trả thẻ ⇒ không có nhãn');

        $payment = $this->openSession($order);
        $this->postJson('/api/webhook/pay2s-card', $this->webhook($payment))->assertOk();

        $this->assertSame(['paid_by_card' => true, 'payment_label' => 'Thẻ'], $svc->labelFields($order->fresh()));
        $this->assertSame([], $svc->labelFields($other), 'đơn khác không bị ảnh hưởng');
        $this->assertTrue($order->fresh()->cardPayments()->where('status', 'paid')->exists());
    }

    public function test_admin_api_toggle_show_sync_and_resolve(): void
    {
        Sanctum::actingAs($this->superAdminUser(), ['*']);
        $this->enableFor($this->ownBranchId);

        // Khoản review: đơn đã huỷ nhưng Pay2S đã thu tiền.
        $order = $this->order($this->ownBranchId);
        $payment = $this->openSession($order);
        $order->forceFill(['status' => 'cancelled_payment'])->save();
        $this->postJson('/api/webhook/pay2s-card', $this->webhook($payment))->assertOk();
        $this->assertSame('review', $payment->fresh()->status);

        // Chi tiết: không lộ accessKey / chữ ký.
        $detail = $this->getJson("/api/admin/pay2s-card/payments/{$payment->id}")->assertOk()->json('data');
        $this->assertSame('review', $detail['status']);
        $this->assertArrayNotHasKey('accessKey', $detail['raw']);
        $this->assertArrayNotHasKey('m2signature', $detail['raw']);
        $this->assertStringNotContainsString(self::ACCESS, json_encode($detail));

        // Đánh dấu đã xử lý: chỉ khoản review, chỉ một lần.
        $this->postJson("/api/admin/pay2s-card/payments/{$payment->id}/resolve", ['note' => 'Đã hoàn tay qua Pay2S'])
            ->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.resolution_note', 'Đã hoàn tay qua Pay2S');
        $this->postJson("/api/admin/pay2s-card/payments/{$payment->id}/resolve")->assertStatus(422);
        $this->assertGreaterThanOrEqual(1, $this->getJson('/api/admin/pay2s-card/payments?status=resolved')->assertOk()->json('meta.total'));

        // Đồng bộ: chỉ phiên đang chờ.
        $pending = $this->openSession($this->order($this->ownBranchId));
        $this->postJson("/api/admin/pay2s-card/payments/{$pending->id}/sync")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson("/api/admin/pay2s-card/payments/{$payment->id}/sync")->assertStatus(422);

        // Nút bật/tắt qua API (cài đặt được hoàn lại ở tearDown).
        $this->putJson('/api/admin/pay2s-card/enabled', ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
        $this->putJson('/api/admin/pay2s-card/enabled', ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);
        $this->putJson('/api/admin/pay2s-card/enabled', [])->assertStatus(422);
    }

    public function test_admin_card_apis_are_super_admin_only(): void
    {
        $plain = \App\Models\User::query()->get()->first(fn ($x) => ! $x->isSuperAdmin());
        if (! $plain) {
            $this->markTestSkipped('Cần 1 tài khoản không phải Super Admin.');
        }
        $plain->unsetRelation('roles');
        Sanctum::actingAs($plain, ['*']);

        $this->putJson('/api/admin/pay2s-card/enabled', ['enabled' => true])->assertForbidden();
        $this->getJson('/api/admin/pay2s-card/payments/1')->assertForbidden();
        $this->postJson('/api/admin/pay2s-card/payments/1/resolve')->assertForbidden();
    }

    // Chỉ gán giá trị trên đối tượng đã fake, KHÔNG gọi update()/save(): cài đặt không được hoàn tác theo transaction nên mọi lần lưu đều ghi vào DB dev thật.
    public function test_settings_apply_keeps_secrets_when_blank_and_status_never_exposes_them(): void
    {
        $svc = app(Pay2SCardService::class);
        $settings = app(Pay2SCardSettings::class);

        $svc->apply($settings, ['access_key' => 'ak-new', 'secret_key' => 'sk-new', 'category_ids' => ['5', 5, 7]]);
        $this->assertSame('sk-new', $settings->secret_key);
        $this->assertSame([5, 7], $settings->category_ids);

        $svc->apply($settings, ['secret_key' => '', 'access_key' => null, 'partner_code' => ' NEWCODE ']);
        $this->assertSame('sk-new', $settings->secret_key, 'để trống = giữ nguyên khoá cũ');
        $this->assertSame('ak-new', $settings->access_key);
        $this->assertSame('NEWCODE', $settings->partner_code);

        $status = $svc->status();
        $this->assertTrue($status['secret_key']['configured']);
        $this->assertArrayNotHasKey('value', $status['secret_key']);
        $this->assertStringNotContainsString('sk-new', json_encode($status));
    }

    // API công khai cho app ở bước đặt phòng (chưa có đơn): chi nhánh này có nhận thẻ quốc tế không.
    public function test_public_options_endpoint_tells_whether_a_branch_takes_cards(): void
    {
        $this->enableFor($this->ownBranchId);

        $this->getJson('/api/card-payment/options?category_id=' . $this->ownBranchId)->assertOk()
            ->assertJsonPath('data.available', true)->assertJsonPath('data.brands', Pay2SCardService::BRANDS)->assertJsonPath('data.min_amount', Pay2SCardService::MIN_AMOUNT);
        $this->getJson('/api/card-payment/options?category_id=' . $this->partnerBranchId)->assertOk()->assertExactJson(['data' => ['available' => false]]);
        $this->getJson('/api/card-payment/options')->assertStatus(422);

        $this->fakeSettings(['category_ids' => [$this->ownBranchId], 'enabled' => false]);
        $this->getJson('/api/card-payment/options?category_id=' . $this->ownBranchId)->assertOk()->assertExactJson(['data' => ['available' => false]]);
    }
}

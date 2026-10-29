<?php

namespace Tests\Feature\Payment;

use App\Models\Partner;
use App\Support\ApprovalContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Payment\App\Services\CccdScannerService;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\PartnerSePayAccount;
use Modules\Payment\Entities\SePayPaymentLink;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\TimeSlot;
use Tests\TestCase;

// ĐẶT PHÒNG THẬT qua API khách (POST /api/guest/orders) của đối tác đang dùng SePay: đơn tạo ra yêu cầu thanh toán SePay, phản hồi có
// dữ liệu QR cho app, khách chuyển khoản (giả lập webhook) thì đơn tự thành "paid"; công tắc SEPAY_ENABLED=false đưa về PayOS.
class SePayOrderApiTest extends TestCase
{
    use DatabaseTransactions;

    private Partner $partner;

    private Product $room;

    private int $timeslotId;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Settings\SePaySettings::fake(['enabled' => null, 'webhook_secret' => null, 'code_prefix' => null, 'api_token' => null, 'min_amount' => null]); // tách khỏi cấu hình CMS thật

        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');
        Http::fake();
        config(['sepay.webhook_secret' => 'flow-secret', 'sepay.code_prefix' => 'DH', 'sepay.enabled' => true]);

        $this->partner = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->firstOrFail();
        $this->partner->forceFill(['payment_flow_effective_at' => now()->subDay()])->save();
        PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->delete();
        PartnerSePayAccount::query()->where('account_number', '0011001234567')->delete();
        PartnerSePayAccount::query()->create(['partner_id' => $this->partner->id, 'is_active' => true, 'bank_code' => 'VCB', 'bank_bin' => '970436', 'account_number' => '0011001234567', 'account_holder' => 'TEST']);

        $branch = Category::withoutGlobalScopes()->forceCreate(['name' => 'CN api ' . Str::random(4), 'slug' => 'cn-api-' . Str::random(6), 'category_type' => 'product', 'status' => 1, 'partner_id' => $this->partner->id]);
        $this->room = ApprovalContext::bypass(fn () => Product::create(['partner_id' => $this->partner->id, 'name' => 'Phong api', 'slug' => 'phong-api-' . Str::random(6), 'styles' => 1, 'price' => 100000, 'is_activated' => true]));
        $this->room->categories()->sync([$branch->id]);

        $slot = TimeSlot::usableBy($this->partner->id)->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'date'))->where('over_night', false)->orderBy('start_time')->firstOrFail();
        $this->timeslotId = $slot->id;
        ApprovalContext::bypass(fn () => RoomTimeSlot::create(['room_id' => $this->room->id, 'timeslot_id' => $slot->id, 'price' => 300000, 'status' => 'available']));

        $this->mock(CccdScannerService::class, fn ($m) => $m->shouldReceive('scanQrImage')->andReturn([
            'cccd' => '087204016918', 'old_id' => '', 'full_name' => 'Nguyễn Văn A', 'dob' => '12/05/2004', 'gender' => 'Nam', 'address' => 'Đồng Tháp', 'issued_date' => '01/01/2022', 'source' => 'qr',
        ]));
    }

    private function book(int $daysAhead = 3): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/guest/orders', [
            'type' => 'slot', 'room_id' => $this->room->id, 'buyer_name' => 'Khach', 'buyer_phone' => '0900000001', 'guest_count' => 1, 'payment_method' => 'PayOS',
            'slots' => [['timeslot_id' => $this->timeslotId, 'date' => now()->addDays($daysAhead)->toDateString()]],
            'cccd_qr_image' => UploadedFile::fake()->image('q.jpg', 800, 600),
        ], ['Accept' => 'application/json']);
    }

    public function test_booking_creates_a_sepay_payment_and_the_transfer_confirms_the_order(): void
    {
        $res = $this->book()->assertCreated();
        $code = $res->json('order.order_code');
        $link = SePayPaymentLink::query()->where('ref_code', $code)->firstOrFail();

        $res->assertJsonPath('order.payment_provider', 'sepay')
            ->assertJsonPath('order.qr_code', null)
            ->assertJsonPath('order.sepay.amount', 300000)
            ->assertJsonPath('order.sepay.payment_code', 'DH' . $link->code)
            ->assertJsonPath('order.sepay.bank.account_number', '0011001234567');
        $this->assertStringContainsString('amount=300000', $res->json('order.sepay.qr_url'));
        $this->assertSame(route('sepay.checkout', ['code' => $link->code]), $res->json('order.sepay.checkout_url'));
        $this->assertMatchesRegularExpression('/^DH\d{8}$/', $res->json('order.sepay.payment_code'));
        $this->assertNotNull($res->json('order.expired_at'), 'đơn có hạn thanh toán để tự huỷ nếu khách không chuyển');

        $this->assertSame('pending', $link->status);
        $this->assertSame('partner', Order::withoutGlobalScopes()->where('order_code', $code)->value('collected_by'));

        // App tra lại đơn: vẫn có dữ liệu QR
        $this->getJson("/api/guest/orders/{$code}?phone=0900000001")->assertOk()->assertJsonPath('order.payment_provider', 'sepay');

        // Khách chuyển khoản → webhook SePay (giả lập, ký HMAC thật) → đơn tự thanh toán
        $this->artisan('sepay:simulate', ['code' => $code])->assertSuccessful();

        $order = Order::withoutGlobalScopes()->where('order_code', $code)->firstOrFail();
        $this->assertSame('paid', $order->status);
        $this->assertSame('paid', $link->fresh()->status);
    }

    public function test_the_kill_switch_sends_everything_back_to_payos(): void
    {
        config(['sepay.enabled' => false]);

        $res = $this->book()->assertCreated();

        $res->assertJsonPath('order.payment_provider', 'payos')->assertJsonPath('order.sepay', null);
        $this->assertSame(0, SePayPaymentLink::query()->where('ref_code', $res->json('order.order_code'))->count());
    }

    public function test_sepay_accepts_a_1000_vnd_payment_but_payos_still_needs_2000(): void
    {
        RoomTimeSlot::query()->where('room_id', $this->room->id)->update(['price' => 1000]);

        $res = $this->book()->assertCreated();
        $res->assertJsonPath('order.sepay.amount', 1000)->assertJsonPath('summary.final_amount', 1000);
        $this->artisan('sepay:simulate', ['code' => $res->json('order.order_code')])->assertSuccessful();
        $this->assertSame('paid', Order::withoutGlobalScopes()->where('order_code', $res->json('order.order_code'))->value('status'));

        config(['sepay.enabled' => false]);
        $payos = $this->book(6)->assertCreated();
        $payos->assertJsonPath('order.payment_provider', 'payos')->assertJsonPath('order.sepay', null);
        $this->assertSame(0, SePayPaymentLink::query()->where('ref_code', $payos->json('order.order_code'))->count(), 'PayOS: dưới 2.000đ không tạo link');
    }
}

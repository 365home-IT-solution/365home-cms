<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

class PaymentWebhookSafetyTest extends TestCase
{
    use DatabaseTransactions;

    private const MOMO_ACCESS_KEY = 'F8BBA842ECF85';
    private const MOMO_SECRET_KEY = 'K951B6PE1waDMi640xX08PD3vg6EkVlz';

    private Building $building;
    private Room $room;
    private Contract $contract;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $this->building = Building::create([
            'zone_id' => $zone->id, 'name' => 'B', 'address' => 'a',
            'electric_unit_price' => 3500, 'water_unit_price' => 15000,
            'payment_method' => Building::PAYMENT_METHOD_MOMO,
            'momo_partner_code' => 'MOMO', 'momo_access_key' => self::MOMO_ACCESS_KEY, 'momo_secret_key' => self::MOMO_SECRET_KEY,
        ]);
        $this->room = Room::create(['building_id' => $this->building->id, 'code' => 'W-01', 'price' => 3000000, 'status' => Room::STATUS_RENTED]);
        $tenant = Tenant::create(['fullname' => 'T', 'phone' => '0911112222', 'room_id' => $this->room->id]);
        $this->contract = Contract::create([
            'room_id' => $this->room->id, 'tenant_id' => $tenant->id,
            'start_date' => now()->subMonth(), 'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);
        $this->invoice = Invoice::create([
            'contract_id' => $this->contract->id, 'month' => now()->startOfMonth(),
            'room_price' => 3000000, 'total_amount' => 3000000, 'amount_paid' => 0,
            'status' => Invoice::STATUS_UNPAID,
            'momo_order_id' => 'MHTEST' . uniqid(),
        ]);
    }

    private function momoPayload(float $amount, string $transId, array $overrides = []): array
    {
        $data = array_merge([
            'partnerCode'  => 'MOMO',
            'orderId'      => $this->invoice->momo_order_id,
            'requestId'    => 'req-' . $transId,
            'amount'       => $amount,
            'orderInfo'    => 'test',
            'orderType'    => 'momo_wallet',
            'transId'      => $transId,
            'resultCode'   => 0,
            'message'      => 'Success',
            'payType'      => 'qr',
            'responseTime' => now()->timestamp,
            'extraData'    => '',
        ], $overrides);

        $raw = 'accessKey=' . self::MOMO_ACCESS_KEY
            . '&amount=' . $data['amount']
            . '&extraData=' . $data['extraData']
            . '&message=' . $data['message']
            . '&orderId=' . $data['orderId']
            . '&orderInfo=' . $data['orderInfo']
            . '&orderType=' . $data['orderType']
            . '&partnerCode=' . $data['partnerCode']
            . '&payType=' . $data['payType']
            . '&requestId=' . $data['requestId']
            . '&responseTime=' . $data['responseTime']
            . '&resultCode=' . $data['resultCode']
            . '&transId=' . $data['transId'];

        $data['signature'] = hash_hmac('sha256', $raw, self::MOMO_SECRET_KEY);

        return $data;
    }

    public function test_momo_webhook_records_a_legitimate_payment(): void
    {
        $this->postJson(route('api.minihouse.webhook.momo'), $this->momoPayload(3000000, 'tx-1'))->assertNoContent();

        $this->invoice->refresh();
        $this->assertEquals(Invoice::STATUS_PAID, $this->invoice->status);
        $this->assertEquals(1, InvoicePayment::where('invoice_id', $this->invoice->id)->count());
    }

    // Lỗi thật đã sửa: MoMo/PayOS trước đây KHÔNG so số tiền báo về với remainingAmount() (khác
    // VnpayIpnController vốn đã làm đúng) — hoá đơn bị sửa tay SAU KHI khách mở link cũ vẫn bị ghi
    // nhận sai số tiền không cảnh báo gì.
    public function test_momo_webhook_rejects_amount_mismatch_and_does_not_record_payment(): void
    {
        $this->postJson(route('api.minihouse.webhook.momo'), $this->momoPayload(1000000, 'tx-mismatch'))->assertNoContent();

        $this->invoice->refresh();
        $this->assertEquals(Invoice::STATUS_UNPAID, $this->invoice->status);
        $this->assertEquals(0, InvoicePayment::where('invoice_id', $this->invoice->id)->count());
    }

    // Lỗi thật đã sửa: webhook trước đây tạo thanh toán trực tiếp KHÔNG qua
    // Invoice::validateSinglePayment() — hoá đơn đã có 1 khoản thanh toán khác (VD nhân viên ghi tay
    // đang chờ duyệt) rồi mà MoMo vẫn báo thành công thì KHÔNG được tự tạo thêm dòng thứ 2.
    public function test_momo_webhook_does_not_double_pay_when_invoice_already_has_a_payment(): void
    {
        InvoicePayment::create([
            'invoice_id' => $this->invoice->id, 'amount' => 3000000, 'paid_at' => now(),
            'payment_method' => InvoicePayment::METHOD_CASH, 'status' => InvoicePayment::STATUS_PENDING,
        ]);

        $this->postJson(route('api.minihouse.webhook.momo'), $this->momoPayload(3000000, 'tx-2'))->assertNoContent();

        $this->assertEquals(1, InvoicePayment::where('invoice_id', $this->invoice->id)->count(), 'MoMo must not create a second payment when one already exists.');
    }

    public function test_momo_webhook_replay_of_same_transaction_is_idempotent(): void
    {
        $payload = $this->momoPayload(3000000, 'tx-3');

        $this->postJson(route('api.minihouse.webhook.momo'), $payload)->assertNoContent();
        $this->postJson(route('api.minihouse.webhook.momo'), $payload)->assertNoContent();

        $this->assertEquals(1, InvoicePayment::where('invoice_id', $this->invoice->id)->count());
    }

    // Lỗi thật đã sửa: trước đây "Duyệt thanh toán" không kiểm tra hoá đơn đã có khoản KHÁC được
    // duyệt hay chưa. Sau khi sửa cả webhook (không tự tạo thêm khi hoá đơn đã có 1 khoản khác — xem
    // test ở trên) lẫn approve(), đường DUY NHẤT còn lại để 1 hoá đơn có 2 dòng thanh toán (1 pending
    // + 1 approved) là dữ liệu tồn tại TỪ TRƯỚC lúc sửa (hoặc ghi trực tiếp ngoài luồng thông thường)
    // — mô phỏng đúng trạng thái đó bằng cách tạo thẳng 2 dòng, không qua webhook nữa (webhook giờ đã
    // tự chặn không cho tạo dòng thứ 2 nên không còn tái hiện được qua đường đó).
    public function test_admin_cannot_approve_a_pending_payment_when_invoice_already_has_an_approved_one(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);

        InvoicePayment::create([
            'invoice_id' => $this->invoice->id, 'amount' => 3000000, 'paid_at' => now(),
            'payment_method' => InvoicePayment::METHOD_TRANSFER, 'status' => InvoicePayment::STATUS_APPROVED,
            'approved_at' => now(), 'note' => 'Thanh toán qua MoMo - transId: legacy',
        ]);
        $this->invoice->refresh();
        $this->assertEquals(3000000, (float) $this->invoice->amount_paid);

        $pending = InvoicePayment::create([
            'invoice_id' => $this->invoice->id, 'amount' => 3000000, 'paid_at' => now(),
            'payment_method' => InvoicePayment::METHOD_CASH, 'status' => InvoicePayment::STATUS_PENDING,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/minihouse/invoices/{$this->invoice->id}/payments/{$pending->id}/approve");

        $response->assertStatus(422);

        $this->invoice->refresh();
        $this->assertEquals(3000000, (float) $this->invoice->amount_paid, 'amount_paid must not double-count the manually-approved duplicate.');
    }
}

<?php

namespace Tests\Feature\Minihouse;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\PortalNotification;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\TenantPushToken;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Kiểm tra 3 điểm parity API Portal mới thêm để giống hệt Home (xem
// App\Http\Controllers\Api\NotificationController và Api\DeviceTokenController): GET không tự đánh
// dấu đã đọc, 2 endpoint đánh dấu đọc riêng, và validate token Firebase trước khi lưu.
class PortalNotificationApiParityTest extends TestCase
{
    use DatabaseTransactions;

    private Building $building;
    private Room $room;
    private Contract $contract;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $this->building = Building::create([
            'zone_id' => $zone->id, 'name' => 'Test Building', 'address' => 'a',
            'electric_unit_price' => 3500, 'water_unit_price' => 15000,
        ]);
        $this->room = Room::create(['building_id' => $this->building->id, 'code' => 'P-01', 'price' => 3000000, 'status' => Room::STATUS_RENTED]);
        $this->tenant = Tenant::create(['fullname' => 'Primary', 'phone' => '0911222333', 'room_id' => $this->room->id]);
        $this->contract = Contract::create([
            'room_id' => $this->room->id, 'tenant_id' => $this->tenant->id,
            'start_date' => now()->subMonth(), 'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);
    }

    private function tokenFor(Tenant $tenant): string
    {
        return $tenant->createToken('test')->plainTextToken;
    }

    public function test_get_notifications_does_not_auto_mark_read(): void
    {
        PortalNotification::create(['tenant_id' => $this->tenant->id, 'type' => PortalNotification::TYPE_ANNOUNCEMENT, 'title' => 'X']);
        PortalNotification::create(['tenant_id' => $this->tenant->id, 'type' => PortalNotification::TYPE_ANNOUNCEMENT, 'title' => 'Y']);

        $token = $this->tokenFor($this->tenant);

        $response = $this->withToken($token)->getJson('/api/minihouse/portal/notifications');
        $response->assertOk();
        $response->assertJsonPath('unread_count', 2);
        $response->assertJsonCount(2, 'data');

        // Gọi GET lần 2 — vẫn phải còn nguyên 2 chưa đọc (KHÔNG tự đánh dấu, khác hành vi cũ).
        $again = $this->withToken($token)->getJson('/api/minihouse/portal/notifications');
        $again->assertJsonPath('unread_count', 2);

        $this->assertEquals(2, PortalNotification::where('tenant_id', $this->tenant->id)->whereNull('read_at')->count());
    }

    public function test_mark_single_notification_read(): void
    {
        $notification = PortalNotification::create(['tenant_id' => $this->tenant->id, 'type' => PortalNotification::TYPE_ANNOUNCEMENT, 'title' => 'X']);
        $other = PortalNotification::create(['tenant_id' => $this->tenant->id, 'type' => PortalNotification::TYPE_ANNOUNCEMENT, 'title' => 'Y']);

        $token = $this->tokenFor($this->tenant);

        $response = $this->withToken($token)->postJson("/api/minihouse/portal/notifications/{$notification->id}/read");
        $response->assertOk();
        $response->assertJsonPath('is_read', true);

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
    }

    public function test_mark_notification_read_rejects_other_tenants_notification(): void
    {
        $otherTenant = Tenant::create(['fullname' => 'Other', 'phone' => '0999888777', 'room_id' => null]);
        $foreignNotification = PortalNotification::create(['tenant_id' => $otherTenant->id, 'type' => PortalNotification::TYPE_ANNOUNCEMENT, 'title' => 'Foreign']);

        $token = $this->tokenFor($this->tenant);

        $this->withToken($token)->postJson("/api/minihouse/portal/notifications/{$foreignNotification->id}/read")->assertStatus(404);
        $this->assertNull($foreignNotification->fresh()->read_at);
    }

    public function test_mark_all_notifications_read(): void
    {
        PortalNotification::create(['tenant_id' => $this->tenant->id, 'type' => PortalNotification::TYPE_ANNOUNCEMENT, 'title' => 'X']);
        PortalNotification::create(['tenant_id' => $this->tenant->id, 'type' => PortalNotification::TYPE_ANNOUNCEMENT, 'title' => 'Y']);

        $token = $this->tokenFor($this->tenant);

        $response = $this->withToken($token)->postJson('/api/minihouse/portal/notifications/read-all');
        $response->assertOk();
        $response->assertJsonPath('updated', 2);

        $this->assertEquals(0, PortalNotification::where('tenant_id', $this->tenant->id)->whereNull('read_at')->count());
    }

    public function test_register_push_token_rejects_invalid_token(): void
    {
        config(['app.fcm_bypass_enabled' => false]);
        $token = $this->tokenFor($this->tenant);

        $response = $this->withToken($token)->postJson('/api/minihouse/portal/push-token', ['token' => 'not-a-valid-token']);
        $response->assertStatus(422);

        $this->assertDatabaseMissing('minihouse_tenant_push_tokens', ['token' => 'not-a-valid-token']);
    }

    public function test_register_push_token_accepts_valid_expo_token_format(): void
    {
        config(['app.fcm_bypass_enabled' => false]);
        $token = $this->tokenFor($this->tenant);
        $expoToken = 'ExponentPushToken[abcDEF123-_xyz]';

        $response = $this->withToken($token)->postJson('/api/minihouse/portal/push-token', ['token' => $expoToken]);
        $response->assertOk();

        $this->assertDatabaseHas('minihouse_tenant_push_tokens', ['token' => $expoToken, 'tenant_id' => $this->tenant->id]);
    }

    public function test_register_push_token_bypassed_when_flag_enabled(): void
    {
        config(['app.fcm_bypass_enabled' => true]);
        $token = $this->tokenFor($this->tenant);

        $response = $this->withToken($token)->postJson('/api/minihouse/portal/push-token', ['token' => 'any-raw-token-value']);
        $response->assertOk();

        $this->assertDatabaseHas('minihouse_tenant_push_tokens', ['token' => 'any-raw-token-value']);
    }

    public function test_invoice_payment_approval_notifies_tenant_exactly_once(): void
    {
        $invoice = Invoice::create([
            'contract_id' => $this->contract->id, 'month' => now()->startOfMonth(),
            'room_price' => 3000000, 'total_amount' => 3000000, 'amount_paid' => 0,
            'status' => Invoice::STATUS_UNPAID,
        ]);

        InvoicePayment::create([
            'invoice_id' => $invoice->id, 'amount' => 3000000, 'paid_at' => now(),
            'payment_method' => InvoicePayment::METHOD_TRANSFER, 'status' => InvoicePayment::STATUS_APPROVED,
        ]);

        $this->assertEquals(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertEquals(1, PortalNotification::where('tenant_id', $this->tenant->id)
            ->where('type', PortalNotification::TYPE_INVOICE_PAID)->count());

        // Sửa lại số điện nước của hoá đơn ĐÃ thanh toán (chỉ số cache, không đổi status) — resync lại
        // vẫn còn STATUS_PAID, KHÔNG được bắn thêm thông báo lần nữa.
        $invoice->fresh()->touch();
        app(\Modules\Minihouse\App\Observers\InvoicePaymentObserver::class)->resyncInvoice($invoice->id);

        $this->assertEquals(1, PortalNotification::where('tenant_id', $this->tenant->id)
            ->where('type', PortalNotification::TYPE_INVOICE_PAID)->count());
    }
}

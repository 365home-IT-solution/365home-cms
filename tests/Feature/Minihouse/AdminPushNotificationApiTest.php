<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\PortalBroadcast;
use Modules\Minihouse\App\Models\PortalNotification;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\TenantPushToken;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Mirror App\Http\Controllers\Api\Admin\PushNotificationController (Home) ở phía MiniHouse — xem
// App\Http\Controllers\Api\Admin\Minihouse\PushNotificationController.
class AdminPushNotificationApiTest extends TestCase
{
    use DatabaseTransactions;

    private Building $building;
    private Room $room;
    private Tenant $tenantWithToken;
    private Tenant $tenantWithoutToken;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // không gọi thật ra Expo/Firebase — token giả trong test sẽ bị Expo trả về
        // "DeviceNotRegistered" và tự xoá khỏi minihouse_tenant_push_tokens (xem FcmService::
        // sendViaExpoToTenant()), làm sai lệch số liệu ở các test gửi 2 lần (VD resend).

        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $this->building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        $this->room = Room::create(['building_id' => $this->building->id, 'code' => 'PN-' . uniqid(), 'price' => 3000000, 'status' => Room::STATUS_RENTED]);

        $this->tenantWithToken = Tenant::create(['fullname' => 'Has Token', 'phone' => '09' . random_int(10000000, 99999999), 'room_id' => $this->room->id]);
        TenantPushToken::create(['tenant_id' => $this->tenantWithToken->id, 'token' => 'ExponentPushToken[abc' . uniqid() . ']']);

        $this->tenantWithoutToken = Tenant::create(['fullname' => 'No Token', 'phone' => '09' . random_int(10000000, 99999999), 'room_id' => $this->room->id]);
    }

    private function adminToken(): string
    {
        $admin = User::role('super_admin')->first();

        return $admin->createToken('t')->plainTextToken;
    }

    public function test_store_sends_immediately_to_all_tenants_with_push_token(): void
    {
        $token = $this->adminToken();

        $response = $this->withToken($token)->postJson('/api/admin/minihouse/push-notification', [
            'title'    => 'Bảo trì thang máy',
            'body'     => 'Thang máy tạm ngưng 9h-11h sáng mai.',
            'sent_for' => PortalBroadcast::SENT_FOR_ALL,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.delivery_status', 'sent');
        $response->assertJsonPath('data.recipient_count', 1);

        $this->assertEquals(1, PortalNotification::where('tenant_id', $this->tenantWithToken->id)
            ->where('title', 'Bảo trì thang máy')->count());
        $this->assertEquals(0, PortalNotification::where('tenant_id', $this->tenantWithoutToken->id)->count());
    }

    public function test_store_sends_to_specific_tenant_ids(): void
    {
        $token = $this->adminToken();

        // tenantWithoutToken vẫn được chọn tay dù không có token — mirror hành vi Home (chọn tay là
        // theo ý admin, không lọc lại theo điều kiện "all").
        $response = $this->withToken($token)->postJson('/api/admin/minihouse/push-notification', [
            'title'      => 'Riêng',
            'body'       => 'x',
            'sent_for'   => PortalBroadcast::SENT_FOR_TENANTS,
            'tenant_ids' => [$this->tenantWithoutToken->id],
        ]);

        $response->assertCreated();
        $this->assertEquals(1, PortalNotification::where('tenant_id', $this->tenantWithoutToken->id)->count());
        $this->assertEquals(0, PortalNotification::where('tenant_id', $this->tenantWithToken->id)->count());
    }

    public function test_store_with_future_scheduled_at_does_not_send_immediately(): void
    {
        $token = $this->adminToken();

        $response = $this->withToken($token)->postJson('/api/admin/minihouse/push-notification', [
            'title'        => 'Lên lịch',
            'body'         => 'x',
            'sent_for'     => PortalBroadcast::SENT_FOR_ALL,
            'scheduled_at' => now()->addHour()->toIso8601String(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.delivery_status', 'scheduled');

        $this->assertEquals(0, PortalNotification::where('title', 'Lên lịch')->count());

        $broadcast = PortalBroadcast::where('title', 'Lên lịch')->first();
        $this->assertNotNull($broadcast->tenant_ids);
        $this->assertNull($broadcast->sent_at);
    }

    public function test_update_fails_once_already_sent(): void
    {
        $token = $this->adminToken();

        $created = $this->withToken($token)->postJson('/api/admin/minihouse/push-notification', [
            'title' => 'Gửi rồi', 'body' => 'x', 'sent_for' => PortalBroadcast::SENT_FOR_ALL,
        ]);
        $id = $created->json('data.id');

        $update = $this->withToken($token)->putJson("/api/admin/minihouse/push-notification/{$id}", [
            'title' => 'Sửa lại', 'body' => 'x', 'sent_for' => PortalBroadcast::SENT_FOR_ALL,
        ]);

        $update->assertStatus(422);
    }

    public function test_resend_creates_a_new_record_preserving_original(): void
    {
        $token = $this->adminToken();

        $created = $this->withToken($token)->postJson('/api/admin/minihouse/push-notification', [
            'title' => 'Gốc', 'body' => 'x', 'sent_for' => PortalBroadcast::SENT_FOR_ALL,
        ]);
        $originalId = $created->json('data.id');

        $resend = $this->withToken($token)->postJson("/api/admin/minihouse/push-notification/{$originalId}/resend");
        $resend->assertCreated();

        $newId = $resend->json('data.id');
        $this->assertNotEquals($originalId, $newId);
        $this->assertNotNull(PortalBroadcast::find($originalId));
        $this->assertEquals(2, PortalNotification::where('tenant_id', $this->tenantWithToken->id)
            ->where('title', 'Gốc')->count());
    }

    public function test_index_and_show(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)->postJson('/api/admin/minihouse/push-notification', [
            'title' => 'A', 'body' => 'x', 'sent_for' => PortalBroadcast::SENT_FOR_ALL,
        ]);

        $index = $this->withToken($token)->getJson('/api/admin/minihouse/push-notification');
        $index->assertOk();
        $index->assertJsonCount(1, 'data');

        $id = $index->json('data.0.id');
        $show = $this->withToken($token)->getJson("/api/admin/minihouse/push-notification/{$id}");
        $show->assertOk();
        $show->assertJsonPath('data.title', 'A');
    }

    public function test_tenant_token_cannot_access_admin_push_notification_api(): void
    {
        $tenantToken = $this->tenantWithToken->createToken('t')->plainTextToken;

        $this->withToken($tenantToken)->getJson('/api/admin/minihouse/push-notification')->assertStatus(403);
    }
}

<?php

namespace Tests\Feature\Minihouse;

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

// Mirror App\Console\Commands\SendScheduledNotificationsCommand (Home) — xem
// App\Console\Commands\Minihouse\SendScheduledPortalBroadcastsCommand, chạy everyMinute() trong
// app/Console/Kernel.php.
class SendScheduledPortalBroadcastsCommandTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'SC-' . uniqid(), 'price' => 3000000, 'status' => Room::STATUS_RENTED]);
        $this->tenant = Tenant::create(['fullname' => 'T', 'phone' => '09' . random_int(10000000, 99999999), 'room_id' => $room->id]);
        TenantPushToken::create(['tenant_id' => $this->tenant->id, 'token' => 'ExponentPushToken[cmd' . uniqid() . ']']);
    }

    public function test_sends_due_scheduled_broadcast(): void
    {
        $broadcast = PortalBroadcast::create([
            'title' => 'Đến giờ', 'body' => 'x', 'sent_for' => PortalBroadcast::SENT_FOR_TENANTS,
            'tenant_ids' => [$this->tenant->id], 'scheduled_at' => now()->subMinute(), 'recipient_count' => 1,
        ]);

        $this->artisan('minihouse:send-scheduled-broadcasts')->assertExitCode(0);

        $broadcast->refresh();
        $this->assertNotNull($broadcast->sent_at);
        $this->assertEquals(1, PortalNotification::where('tenant_id', $this->tenant->id)->where('title', 'Đến giờ')->count());
    }

    public function test_does_not_send_future_scheduled_broadcast(): void
    {
        PortalBroadcast::create([
            'title' => 'Chưa đến giờ', 'body' => 'x', 'sent_for' => PortalBroadcast::SENT_FOR_TENANTS,
            'tenant_ids' => [$this->tenant->id], 'scheduled_at' => now()->addHour(), 'recipient_count' => 1,
        ]);

        $this->artisan('minihouse:send-scheduled-broadcasts')->assertExitCode(0);

        $this->assertEquals(0, PortalNotification::where('title', 'Chưa đến giờ')->count());
    }

    public function test_does_not_resend_already_sent_broadcast(): void
    {
        PortalBroadcast::create([
            'title' => 'Đã gửi rồi', 'body' => 'x', 'sent_for' => PortalBroadcast::SENT_FOR_TENANTS,
            'tenant_ids' => [$this->tenant->id], 'scheduled_at' => now()->subHour(), 'sent_at' => now()->subMinutes(30), 'recipient_count' => 1,
        ]);

        $this->artisan('minihouse:send-scheduled-broadcasts')->assertExitCode(0);

        $this->assertEquals(0, PortalNotification::where('title', 'Đã gửi rồi')->count());
    }

    public function test_broadcast_with_empty_tenant_ids_is_marked_sent_without_error(): void
    {
        $broadcast = PortalBroadcast::create([
            'title' => 'Rỗng', 'body' => 'x', 'sent_for' => PortalBroadcast::SENT_FOR_TENANTS,
            'tenant_ids' => [], 'scheduled_at' => now()->subMinute(), 'recipient_count' => 0,
        ]);

        $this->artisan('minihouse:send-scheduled-broadcasts')->assertExitCode(0);

        $this->assertNotNull($broadcast->fresh()->sent_at);
    }
}

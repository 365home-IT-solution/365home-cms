<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\ChatConversation;
use Modules\Minihouse\App\Models\ChatMessage;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Chat MiniHouse mirror luồng realtime của chat Home: nhân viên nhắn → khách nhận qua socket cá nhân
// + push; khách chỉ chat được trong luồng hợp đồng CỦA MÌNH; app có danh sách luồng + badge chưa đọc.
class ChatRealtimeFlowTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Tenant $stranger;
    private Contract $contract;
    private Contract $foreignContract;

    protected function setUp(): void
    {
        parent::setUp();

        $building = Building::create(['zone_id' => Zone::create(['name' => 'Z' . uniqid()])->id, 'name' => 'Toà A', 'address' => 'a']);
        $room     = Room::create(['building_id' => $building->id, 'code' => 'A-101', 'price' => 3000000, 'status' => Room::STATUS_RENTED]);
        $room2    = Room::create(['building_id' => $building->id, 'code' => 'A-102', 'price' => 3000000, 'status' => Room::STATUS_RENTED]);

        $this->tenant   = Tenant::create(['fullname' => 'Khách', 'phone' => '0922000001', 'room_id' => $room->id]);
        $this->stranger = Tenant::create(['fullname' => 'Người lạ', 'phone' => '0922000002', 'room_id' => $room2->id]);

        $this->contract = Contract::create([
            'room_id' => $room->id, 'tenant_id' => $this->tenant->id, 'start_date' => now()->subMonth(),
            'monthly_price' => 3000000, 'deposit_amount' => 0, 'status' => Contract::STATUS_ACTIVE,
        ]);
        $this->foreignContract = Contract::create([
            'room_id' => $room2->id, 'tenant_id' => $this->stranger->id, 'start_date' => now()->subMonth(),
            'monthly_price' => 3000000, 'deposit_amount' => 0, 'status' => Contract::STATUS_ACTIVE,
        ]);
    }

    private function tenantHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->tenant->createToken('t')->plainTextToken];
    }

    private function adminHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . User::role('super_admin')->first()->createToken('t')->plainTextToken];
    }

    public function test_tenant_cannot_post_into_someone_elses_contract_thread(): void
    {
        Http::fake();

        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/minihouse/portal/chat/messages', ['body' => 'hi', 'contract_id' => $this->foreignContract->id])
            ->assertStatus(422);

        $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/minihouse/portal/chat?contract_id=' . $this->foreignContract->id)
            ->assertStatus(404);

        $this->assertSame(0, ChatMessage::count());
    }

    public function test_admin_cannot_post_into_contract_of_another_tenant(): void
    {
        Http::fake();
        $conversation = app(\Modules\Minihouse\App\Services\MinihouseChatService::class)->conversationFor($this->tenant);

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/minihouse/chat/{$conversation->id}/messages", ['body' => 'hi', 'contract_id' => $this->foreignContract->id])
            ->assertStatus(422);
    }

    public function test_admin_message_notifies_tenant_socket_and_push(): void
    {
        Http::fake();

        $fcm = \Mockery::mock(FcmService::class);
        $fcm->shouldReceive('sendToTenant')
            ->once()
            ->withArgs(fn ($tenant, $title, $body, $data) => $tenant->id === $this->tenant->id
                && $body === 'Chào bạn'
                && $data['room_code'] === 'A-101'
                && $data['contract_id'] === (string) $this->contract->id);
        $this->app->instance(FcmService::class, $fcm);

        $conversation = app(\Modules\Minihouse\App\Services\MinihouseChatService::class)->conversationFor($this->tenant);

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/minihouse/chat/{$conversation->id}/messages", ['body' => 'Chào bạn', 'contract_id' => $this->contract->id])
            ->assertStatus(201);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/internal/mh-chat-tenant-notify')
            && $request['tenant_id'] === $this->tenant->id
            && $request['payload']['room_code'] === 'A-101'
            && $request['payload']['tenant_unread'] === 1);

        $this->assertSame($this->contract->id, $conversation->fresh()->contract_id);
    }

    public function test_push_failure_does_not_block_sending(): void
    {
        Http::fake();

        $fcm = \Mockery::mock(FcmService::class);
        $fcm->shouldReceive('sendToTenant')->andThrow(new \RuntimeException('fcm down'));
        $this->app->instance(FcmService::class, $fcm);

        $conversation = app(\Modules\Minihouse\App\Services\MinihouseChatService::class)->conversationFor($this->tenant);

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/minihouse/chat/{$conversation->id}/messages", ['body' => 'x'])
            ->assertStatus(201);

        $this->assertSame(1, ChatMessage::count());
    }

    public function test_threads_and_unread_do_not_mark_messages_read(): void
    {
        Http::fake();

        $fcm = \Mockery::mock(FcmService::class);
        $fcm->shouldReceive('sendToTenant');
        $this->app->instance(FcmService::class, $fcm);

        $svc          = app(\Modules\Minihouse\App\Services\MinihouseChatService::class);
        $conversation = $svc->conversationFor($this->tenant);
        $svc->send($conversation, 'admin', 'u1', 'chung', 'Admin');
        $svc->send($conversation->fresh(), 'admin', 'u1', 'phòng', 'Admin', $this->contract->id);

        $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/minihouse/portal/chat/unread')
            ->assertOk()
            ->assertJsonPath('unread', 2);

        $threads = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/minihouse/portal/chat/threads')
            ->assertOk()
            ->json('data');

        $general  = collect($threads)->firstWhere('type', 'general');
        $thread   = collect($threads)->firstWhere('contract_id', $this->contract->id);

        $this->assertSame(1, $general['unread']);
        $this->assertSame(1, $thread['unread']);
        $this->assertSame('A-101', $thread['room_code']);
        $this->assertSame('Toà A', $thread['building_name']);
        $this->assertSame('phòng', $thread['last_message']);

        // chưa đánh dấu đã đọc sau khi gọi 2 endpoint trên
        $this->assertSame(2, ChatConversation::first()->tenant_unread);

        // Sanctum giữ lại user của request trước trong cùng 1 test — bỏ đi để đổi sang tài khoản admin.
        $this->app['auth']->forgetGuards();

        $this->withHeaders($this->adminHeaders())
            ->getJson("/api/admin/minihouse/chat/{$conversation->id}/contracts")
            ->assertOk()
            ->assertJsonPath('data.0.type', 'general');
    }
}

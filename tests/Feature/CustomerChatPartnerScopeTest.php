<?php

namespace Tests\Feature;

use App\Filament\Pages\CustomerChat;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Payment\Entities\Order;
use Tests\TestCase;

// Trang CMS "Tin nhắn": 1 khách đặt ở 2 đối tác và nhắn ở cả 2 đơn — mỗi đối tác chỉ thấy khung chat đơn của mình
// (+ tin hỗ trợ chung), không thấy đơn / tin / đoạn xem trước của đối tác kia. Super admin thấy hết.
class CustomerChatPartnerScopeTest extends TestCase
{
    use DatabaseTransactions;

    private Order $orderA;

    private Order $orderB;

    private ChatConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Http::fake();

        $partners = DB::table('orders')->whereNotNull('customer_id')->whereNotNull('partner_id')
            ->distinct()->limit(2)->pluck('partner_id');

        if ($partners->count() < 2) {
            $this->markTestSkipped('Cần đơn có khách của ít nhất 2 đối tác.');
        }

        $this->orderA = Order::withoutGlobalScopes()->where('partner_id', $partners[0])->whereNotNull('customer_id')->firstOrFail();
        $this->orderB = Order::withoutGlobalScopes()->where('partner_id', $partners[1])->whereNotNull('customer_id')->firstOrFail();
        // Cùng 1 khách đặt ở cả 2 đối tác.
        Order::withoutGlobalScopes()->whereKey($this->orderB->id)->update(['customer_id' => $this->orderA->customer_id]);

        $this->conv = ChatConversation::firstOrCreate(['customer_id' => $this->orderA->customer_id], ['status' => 'open']);
        foreach ([[null, 'Tin chung'], [$this->orderA->id, 'Tin don A'], [$this->orderB->id, 'Tin don B bi mat']] as [$orderId, $body]) {
            ChatMessage::create(['conversation_id' => $this->conv->id, 'order_id' => $orderId, 'sender_type' => 'customer', 'sender_id' => $this->orderA->customer_id, 'body' => $body]);
        }
        $this->conv->update(['last_message_preview' => 'Tin don B bi mat', 'last_message_at' => now()->addMinute(), 'admin_unread' => 3]);
    }

    private function partnerUser(string $partnerId): User
    {
        $user = User::forceCreate([
            'fullname'   => 'Chat scope ' . Str::random(6),
            'email'      => 'chat-scope-' . Str::random(10) . '@example.test',
            'password'   => bcrypt('secret'),
            'partner_id' => $partnerId,
        ]);
        $user->givePermissionTo('page_CustomerChat');

        return $user;
    }

    public function test_partner_only_sees_own_order_thread(): void
    {
        $this->actingAs($this->partnerUser($this->orderA->partner_id));

        $page = Livewire::test(CustomerChat::class);
        $conv = collect($page->get('conversations'))->firstWhere('id', $this->conv->id);

        $this->assertNotNull($conv, 'Đối tác A phải thấy hội thoại của khách mình.');
        $this->assertNotSame('Tin don B bi mat', $conv['preview']);

        $page->call('selectConversation', $this->conv->id);
        $orderIds = collect($page->get('customerOrders'))->pluck('id');
        $this->assertContains((string) $this->orderA->id, $orderIds);
        $this->assertNotContains((string) $this->orderB->id, $orderIds);

        // Mở khung chat đơn của đối tác khác → bị chặn, vẫn ở khung chung.
        $page->call('selectOrder', (string) $this->orderB->id)->assertSet('selectedOrderId', '__general__');
        $this->assertNotContains('Tin don B bi mat', collect($page->get('messages'))->pluck('body'));

        // Đọc tin của mình không làm mất trạng thái chưa đọc của tin về đơn B.
        $this->assertSame(1, (int) $this->conv->fresh()->admin_unread);

        $page->call('selectOrder', (string) $this->orderA->id)->assertSet('selectedOrderId', (string) $this->orderA->id);
        $this->assertContains('Tin don A', collect($page->get('messages'))->pluck('body'));
    }

    public function test_partner_cannot_tamper_selected_conversation(): void
    {
        $this->actingAs($this->partnerUser($this->orderA->partner_id));

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(CustomerChat::class)->set('selectedId', $this->conv->id);
    }

    public function test_super_admin_sees_everything(): void
    {
        $this->actingAs(User::role(config('filament-shield.super_admin.name'))->first());

        $page = Livewire::test(CustomerChat::class)->call('selectConversation', $this->conv->id);
        $orderIds = collect($page->get('customerOrders'))->pluck('id');

        $this->assertContains((string) $this->orderA->id, $orderIds);
        $this->assertContains((string) $this->orderB->id, $orderIds);
    }

    public function test_admin_api_keeps_same_partner_scope(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $token   = $this->partnerUser($this->orderA->partner_id)->createToken('t')->plainTextToken;
        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];

        $codes = collect($this->withHeaders($headers)->getJson('/api/admin/chat/' . $this->conv->id . '/orders')->assertOk()->json('data'))->pluck('order_code');
        $this->assertContains($this->orderA->order_code, $codes);
        $this->assertNotContains($this->orderB->order_code, $codes);

        $bodies = collect($this->withHeaders($headers)->getJson('/api/admin/chat/' . $this->conv->id)->assertOk()->json('messages'))->pluck('body');
        $this->assertNotContains('Tin don B bi mat', $bodies);

        $this->withHeaders($headers)->getJson('/api/admin/chat/' . $this->conv->id . '?order_code=' . $this->orderB->order_code)->assertNotFound();
    }
}

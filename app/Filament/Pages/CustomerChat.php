<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\ChatRealtimeService;
use App\Services\NotificationFcmService;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Modules\Payment\Entities\Order;

class CustomerChat extends Page
{
    protected static string  $view             = 'filament.pages.customer-chat';
    protected static ?string $navigationGroup  = 'Quản lý';
    protected static ?string $navigationIcon   = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel  = 'Tin nhắn khách hàng';
    protected static ?string $title            = 'Tin nhắn khách hàng';
    protected static ?int    $navigationSort   = 98;

    public ?string $selectedId           = null;
    public ?array  $selectedConversation = null;
    public array   $conversations        = [];
    public array   $messages             = [];
    public array   $customerOrders       = [];
    public ?string $selectedOrderId      = null;
    public ?array  $selectedOrderInfo    = null;
    public string  $draft                = '';

    public static function canAccess(): bool
    {
        return \Filament\Facades\Filament::auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function mount(): void
    {
        $this->loadConversations();
    }

    public function loadConversations(): void
    {
        $this->conversations = ChatConversation::with('customer:id,fullname,phone')
            ->orderByDesc('last_message_at')
            ->get()
            ->map(fn ($c) => [
                'id'       => $c->id,
                'unread'   => $c->admin_unread,
                'preview'  => $c->last_message_preview,
                'at'       => $c->last_message_at?->diffForHumans(),
                'customer' => [
                    'id'       => $c->customer?->id,
                    'fullname' => $c->customer?->fullname ?? 'Khách',
                    'phone'    => $c->customer?->phone ?? '',
                ],
            ])
            ->toArray();
    }

    public function selectConversation(string $id): void
    {
        $this->selectedId        = $id;
        $this->draft             = '';
        $this->selectedOrderId   = '__general__';
        $this->selectedOrderInfo = null;

        $conv = ChatConversation::with(['customer:id,fullname,phone'])->find($id);
        if (! $conv) {
            return;
        }

        $this->selectedConversation = [
            'id'       => $conv->id,
            'status'   => $conv->status,
            'customer' => [
                'id'       => $conv->customer?->id,
                'fullname' => $conv->customer?->fullname ?? 'Khách',
                'phone'    => $conv->customer?->phone ?? '',
            ],
        ];

        $this->loadCustomerOrders($conv->customer?->id);

        $this->messages = ChatMessage::where('conversation_id', $id)
            ->whereNull('order_id')
            ->orderBy('id')
            ->get()
            ->map(fn ($m) => $this->formatMsg($m))
            ->toArray();

        if ($conv->admin_unread > 0) {
            $conv->admin_unread = 0;
            $conv->save();

            ChatMessage::where('conversation_id', $id)
                ->where('sender_type', 'customer')
                ->whereNull('read_at')
                ->update(['read_at' => Carbon::now()]);

            app(ChatRealtimeService::class)->broadcastRead($id, 'admin');
        }

        foreach ($this->conversations as &$c) {
            if ($c['id'] === $id) {
                $c['unread'] = 0;
            }
        }

        $this->dispatch('subscribeToConversation', id: $id);
        $this->dispatch('scrollToBottom');
    }

    public function selectOrder(string $orderId): void
    {
        if (! $this->selectedId) {
            return;
        }

        $this->selectedOrderId   = $orderId;
        $this->selectedOrderInfo = null;

        if ($orderId === '__general__') {
            $this->messages = ChatMessage::where('conversation_id', $this->selectedId)
                ->whereNull('order_id')
                ->orderBy('id')
                ->get()
                ->map(fn ($m) => $this->formatMsg($m))
                ->toArray();

            $this->dispatch('scrollToBottom');
            return;
        }

        $order = Order::with(['items.product', 'services'])->find($orderId);
        if (! $order) {
            $this->messages = [];
            return;
        }

        $this->selectedOrderInfo = $this->buildOrderInfo($order);

        $this->messages = ChatMessage::where('conversation_id', $this->selectedId)
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->get()
            ->map(fn ($m) => $this->formatMsg($m))
            ->toArray();

        $this->dispatch('scrollToBottom');
    }

    public function sendMessage(): void
    {
        $body = trim($this->draft);
        if (! $body || ! $this->selectedId) {
            return;
        }

        $conv = ChatConversation::with('customer')->find($this->selectedId);
        if (! $conv) {
            return;
        }

        $admin = Auth::user();
        $msg   = ChatMessage::create([
            'conversation_id' => $conv->id,
            'order_id'        => ($this->selectedOrderId && $this->selectedOrderId !== '__general__') ? $this->selectedOrderId : null,
            'sender_type'     => 'admin',
            'sender_id'       => $admin->id,
            'body'            => $body,
        ]);

        $preview           = mb_substr($body, 0, 100);
        $newCustomerUnread = $conv->customer_unread + 1;

        $conv->update([
            'last_message_preview' => $preview,
            'last_message_at'      => $msg->created_at,
            'customer_unread'      => $newCustomerUnread,
        ]);

        $adminName   = $admin->fullname ?? $admin->email;
        $formatted   = $this->formatMsg($msg, $adminName);
        $this->messages[] = $formatted;
        $this->draft = '';

        // WebSocket broadcast
        $realtime = app(ChatRealtimeService::class);
        $realtime->broadcastMessage($conv->id, $formatted);
        $realtime->notifyAdminList(
            $conv->id,
            $preview,
            $msg->created_at->toIso8601String(),
            0,
            $conv->customer ? [
                'id'       => $conv->customer->id,
                'fullname' => $conv->customer->fullname,
                'phone'    => $conv->customer->phone,
            ] : []
        );

        // FCM push notification cho khách
        if ($conv->customer?->token_device) {
            try {
                app(NotificationFcmService::class)->sendToCustomer(
                    $conv->customer,
                    'Tin nhắn từ Quản trị viên',
                    $preview,
                    'chat',
                    ['conversation_id' => $conv->id]
                );
            } catch (\Throwable $e) {
                Log::warning('Chat FCM push failed', [
                    'customer_id' => $conv->customer->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        // Cập nhật preview trong conversation list
        foreach ($this->conversations as &$c) {
            if ($c['id'] === $conv->id) {
                $c['preview'] = $preview;
                $c['at']      = 'vừa xong';
            }
        }

        $this->dispatch('scrollToBottom');
    }

    public function newChatMessage(array $message, string $conversationId): void
    {
        if ($conversationId !== $this->selectedId || ! $this->selectedOrderId) {
            return;
        }

        $msgOrderId = $message['order_id'] ?? null;
        $isGeneral  = $this->selectedOrderId === '__general__';

        if ($isGeneral && $msgOrderId !== null) {
            return;
        }
        if (! $isGeneral && $msgOrderId !== $this->selectedOrderId) {
            return;
        }

        $ids = array_column($this->messages, 'id');
        if (in_array($message['id'], $ids, true)) {
            return;
        }

        if (! isset($message['time'])) {
            $message['time'] = Carbon::parse($message['created_at'])->format('H:i');
        }

        $this->messages[] = $message;
        $this->dispatch('scrollToBottom');
    }

    /**
     * Cập nhật danh sách conversation khi có tin nhắn mới từ bất kỳ khách nào.
     */
    #[On('refreshConversationList')]
    public function refreshConversationList(): void
    {
        $this->loadConversations();
    }

    private function formatMsg(ChatMessage $msg, ?string $senderName = null): array
    {
        return [
            'id'          => $msg->id,
            'order_id'    => $msg->order_id,
            'sender_type' => $msg->sender_type,
            'sender_id'   => $msg->sender_id,
            'sender_name' => $senderName,
            'body'        => $msg->body,
            'read_at'     => $msg->read_at?->toIso8601String(),
            'time'        => $msg->created_at->format('H:i'),
            'created_at'  => $msg->created_at->toIso8601String(),
        ];
    }

    private function loadCustomerOrders(?string $customerId): void
    {
        if (! $customerId) {
            $this->customerOrders = [];
            return;
        }

        $this->customerOrders = Order::where('customer_id', $customerId)
            ->with(['items.product'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($o) => [
                'id'         => (string) $o->id,
                'order_code' => $o->order_code,
                'status'     => $o->status,
                'room_name'  => $o->items->first()?->product?->name,
                'created_at' => $o->created_at?->format('d/m/Y'),
            ])
            ->toArray();
    }

    private function buildOrderInfo(Order $order): array
    {
        $firstItem = $order->items->first();
        $product   = $firstItem?->product;

        $slots = $order->items->map(fn ($item) => [
            'date'  => $item->checkin_date?->format('Y-m-d'),
            'price' => (int) $item->price,
        ])->values()->toArray();

        $services = $order->services->map(fn ($s) => [
            'service_name' => $s->service_name,
            'quantity'     => $s->quantity,
            'subtotal'     => $s->subtotal,
        ])->values()->toArray();

        $slotsTotal    = (int) $order->items->sum('price');
        $servicesTotal = (int) $order->services->sum('subtotal');
        $depositPct    = $order->deposit_percent !== null ? (int) $order->deposit_percent : null;

        if ($depositPct !== null && $depositPct > 0 && $depositPct < 100) {
            $realFinal     = (int) round((int) $order->full_amount * 100 / $depositPct);
            $totalDiscount = max(0, (int) $order->amount - $realFinal);
        } else {
            $realFinal     = (int) $order->full_amount;
            $totalDiscount = max(0, (int) $order->amount - (int) $order->full_amount);
        }

        return [
            'order_code'     => $order->order_code,
            'status'         => $order->status,
            'payment_method' => $order->payment_method,
            'buyer_name'     => $order->buyer_name,
            'buyer_phone'    => $order->buyer_phone,
            'room_name'      => $product?->name,
            'slots'          => $slots,
            'services'       => $services,
            'deposit_pct'    => $depositPct,
            'deposit_amount' => $depositPct ? (int) $order->full_amount : null,
            'remaining'      => $depositPct ? max(0, $realFinal - (int) $order->full_amount) : null,
            'total'          => $realFinal,
            'final_amount'   => (int) $order->full_amount,
            'discount'       => $totalDiscount,
            'services_total' => $servicesTotal,
        ];
    }
}

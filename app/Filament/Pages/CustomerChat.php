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
    public string  $draft                = '';

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
        $this->selectedId = $id;
        $this->draft      = '';

        $conv = ChatConversation::with('customer:id,fullname,phone')->find($id);
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

        $this->messages = ChatMessage::where('conversation_id', $id)
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

        // Cập nhật unread trong list local
        foreach ($this->conversations as &$c) {
            if ($c['id'] === $id) {
                $c['unread'] = 0;
            }
        }

        // Báo JS subscribe vào conversation channel
        $this->dispatch('subscribeToConversation', id: $id);
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

    /**
     * Nhận tin nhắn mới qua WebSocket (dispatch từ JS).
     */
    #[On('newChatMessage')]
    public function newChatMessage(array $message, string $conversationId): void
    {
        if ($conversationId !== $this->selectedId) {
            return;
        }

        $ids = array_column($this->messages, 'id');
        if (in_array($message['id'], $ids, true)) {
            return; // Đã có, bỏ qua
        }

        // Thêm time nếu thiếu (từ API customer không có field này)
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
            'sender_type' => $msg->sender_type,
            'sender_id'   => $msg->sender_id,
            'sender_name' => $senderName,
            'body'        => $msg->body,
            'read_at'     => $msg->read_at?->toIso8601String(),
            'time'        => $msg->created_at->format('H:i'),
            'created_at'  => $msg->created_at->toIso8601String(),
        ];
    }
}

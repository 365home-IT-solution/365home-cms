<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Modules\Minihouse\App\Models\ChatConversation;
use Modules\Minihouse\App\Models\ChatMessage;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Services\MinihouseChatService;

// Chat khách thuê <-> nhân viên (phía nhân viên, giao diện Filament) — mirror App\Filament\Pages\
// CustomerChat (Home) ĐẦY ĐỦ 3 cột: danh sách hội thoại / khung chat / hợp đồng (mirror cột "orders"
// của Home) — mỗi hợp đồng là 1 luồng chat riêng (contract_id), '__general__' là luồng hỗ trợ chung.
// Dùng CHUNG MinihouseChatService với Portal web/API để không lệch dữ liệu.
class TenantChat extends Page
{
    protected static string  $view            = 'minihouse::filament.pages.tenant-chat';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationIcon  = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'Tin nhắn';
    protected static ?string $title           = 'Tin nhắn khách thuê';
    protected static ?int    $navigationSort  = 25;

    public ?string $selectedId           = null;
    public ?array  $selectedConversation = null;
    public array   $conversations        = [];
    public array   $messages             = [];
    public array   $tenantContracts      = [];
    public int     $generalUnread        = 0;
    public ?string $selectedContractId   = null;
    public bool    $hasMore              = false;
    public string  $draft                = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('page_TenantChat') ?? false;
    }

    public function mount(): void
    {
        $this->loadConversations();
    }

    private function service(): MinihouseChatService
    {
        return app(MinihouseChatService::class);
    }

    // Phạm vi toà nhà — CÙNG nguyên tắc User::rootBuildingIds() đang dùng ở mọi nơi khác trong
    // module (rỗng = không giới hạn, xem docblock rootBuildingIds()).
    private function permittedBuildingIds(): array
    {
        $user = Auth::user();

        return $user?->rootBuildingIds() ?? [];
    }

    private function tenantLabel(ChatConversation $c): array
    {
        return [
            'id'       => $c->tenant?->id,
            'fullname' => $c->tenant?->fullname ?? 'Khách thuê',
            'phone'    => $c->tenant?->phone ?? '',
        ];
    }

    public function loadConversations(): void
    {
        $buildingIds = $this->permittedBuildingIds();

        $query = ChatConversation::with('tenant:id,fullname,phone')
            ->orderByDesc('last_message_at');

        if (! empty($buildingIds)) {
            $query->whereIn('building_id', $buildingIds);
        }

        $this->conversations = $query->get()
            ->map(fn (ChatConversation $c) => [
                'id'          => $c->id,
                'unread'      => $c->admin_unread,
                'preview'     => $c->last_message_preview,
                'at'          => $c->last_message_at?->diffForHumans(),
                'tenant'      => $this->tenantLabel($c),
            ])
            ->toArray();

        if ($this->selectedId) {
            foreach ($this->conversations as &$c) {
                if ($c['id'] === $this->selectedId) {
                    $c['unread'] = 0;
                    break;
                }
            }
        }
    }

    public function selectConversation(string $id): void
    {
        $conv = ChatConversation::with('tenant:id,fullname,phone')->find($id);
        if (! $conv) {
            return;
        }

        $this->selectedId          = $id;
        $this->draft               = '';
        $this->selectedContractId  = '__general__';
        $this->selectedConversation = [
            'id'     => $conv->id,
            'status' => $conv->status,
            'tenant' => $this->tenantLabel($conv),
        ];

        $this->loadTenantContracts($conv->tenant_id, $conv->id);
        $this->service()->markReadByAdmin($conv);

        $recent         = $this->service()->recentMessages($conv);
        $this->messages = $recent['messages'];
        $this->hasMore  = $recent['has_more'];

        foreach ($this->conversations as &$c) {
            if ($c['id'] === $id) {
                $c['unread'] = 0;
            }
        }

        $this->dispatch('subscribeToConversation', id: $id);
        $this->dispatch('scrollToBottom');
    }

    public function selectContract(string $contractId): void
    {
        if (! $this->selectedId) {
            return;
        }

        $conv = ChatConversation::find($this->selectedId);
        if (! $conv) {
            return;
        }

        $this->selectedContractId = $contractId;
        $realContractId = $contractId !== '__general__' ? (int) $contractId : null;

        $recent         = $this->service()->recentMessages($conv, $realContractId);
        $this->messages = $recent['messages'];
        $this->hasMore  = $recent['has_more'];

        $this->service()->markReadByAdmin($conv);
        $this->loadTenantContracts($conv->tenant_id, $conv->id);
        $this->dispatch('scrollToBottom');
    }

    public function loadOlder(): void
    {
        if (! $this->selectedId || empty($this->messages)) {
            return;
        }

        $conv = ChatConversation::find($this->selectedId);
        if (! $conv) {
            return;
        }

        $realContractId = ($this->selectedContractId && $this->selectedContractId !== '__general__') ? (int) $this->selectedContractId : null;

        $older = $this->service()->olderMessages($conv, $this->messages[0]['id'], $realContractId);
        $this->messages = [...$older['messages'], ...$this->messages];
        $this->hasMore  = $older['has_more'];
    }

    public function sendMessage(): void
    {
        $body = trim($this->draft);
        if (! $body || ! $this->selectedId) {
            return;
        }

        $conv = ChatConversation::with('tenant')->find($this->selectedId);
        if (! $conv) {
            return;
        }

        $admin     = Auth::user();
        $adminName = $admin->fullname ?? $admin->email;
        $realContractId = ($this->selectedContractId && $this->selectedContractId !== '__general__') ? (int) $this->selectedContractId : null;

        $message = $this->service()->send($conv, ChatMessage::SENDER_ADMIN, (string) $admin->id, $body, $adminName, $realContractId);

        $this->messages[] = $this->service()->formatMessage($message, $adminName);
        $this->draft      = '';

        foreach ($this->conversations as &$c) {
            if ($c['id'] === $conv->id) {
                $c['preview'] = mb_substr($body, 0, 100);
                $c['at']      = 'vừa xong';
            }
        }

        $this->dispatch('scrollToBottom');
    }

    // Nhận từ JS khi có sự kiện socket 'mhchat.message' — xem view. Chỉ thêm vào nếu ĐANG mở đúng
    // conversation VÀ đúng luồng hợp đồng đang xem, tránh lẫn tin của luồng khác.
    public function newChatMessage(array $message, string $conversationId): void
    {
        if ($conversationId !== $this->selectedId || ! $this->selectedContractId) {
            return;
        }

        $msgContractId = $message['contract_id'] ?? null;
        $isGeneral     = $this->selectedContractId === '__general__';

        if ($isGeneral && $msgContractId !== null) {
            return;
        }
        if (! $isGeneral && (string) $msgContractId !== $this->selectedContractId) {
            return;
        }

        $ids = array_column($this->messages, 'id');
        if (in_array($message['id'], $ids, true)) {
            return;
        }

        $this->messages[] = $message;
        $this->dispatch('scrollToBottom');

        if ($message['sender_type'] === ChatMessage::SENDER_TENANT) {
            $conv = ChatConversation::find($conversationId);
            if ($conv) {
                $this->service()->markReadByAdmin($conv);
            }
        }
    }

    #[On('refreshConversationList')]
    public function refreshConversationList(): void
    {
        $this->loadConversations();
    }

    // Dự phòng khi lỡ sự kiện socket — cùng cơ chế poll/backup của Home (wire:poll ở view).
    public function checkNewMessages(): void
    {
        if (! $this->selectedId || ! $this->selectedContractId) {
            return;
        }

        $conv = ChatConversation::find($this->selectedId);
        if (! $conv) {
            return;
        }

        $realContractId = ($this->selectedContractId && $this->selectedContractId !== '__general__') ? (int) $this->selectedContractId : null;

        $lastId = ! empty($this->messages) ? $this->messages[array_key_last($this->messages)]['id'] : null;

        $query = ChatMessage::where('conversation_id', $this->selectedId)
            ->when($realContractId === null, fn ($q) => $q->whereNull('contract_id'), fn ($q) => $q->where('contract_id', $realContractId));

        if ($lastId) {
            $query->where('id', '>', $lastId);
        }

        $newMessages = $query->orderBy('id')->get();

        if ($newMessages->isNotEmpty()) {
            foreach ($newMessages as $m) {
                $this->messages[] = $this->service()->formatMessage($m);
            }
            $this->service()->markReadByAdmin($conv);
            $this->loadConversations();
            $this->loadTenantContracts($conv->tenant_id, $conv->id);
            $this->dispatch('scrollToBottom');
        }
    }

    // Sidebar hợp đồng (mirror loadCustomerOrders() của Home) — kèm badge chưa đọc theo từng
    // contract_id, tính từ chat_messages của đúng conversation đang mở.
    private function loadTenantContracts(?int $tenantId, ?string $conversationId = null): void
    {
        if (! $tenantId) {
            $this->tenantContracts = [];
            $this->generalUnread   = 0;
            return;
        }

        $unreadMap = [];
        $this->generalUnread = 0;

        if ($conversationId) {
            ChatMessage::where('conversation_id', $conversationId)
                ->where('sender_type', ChatMessage::SENDER_TENANT)
                ->whereNull('read_at')
                ->selectRaw('contract_id, COUNT(*) as cnt')
                ->groupBy('contract_id')
                ->get()
                ->each(function ($row) use (&$unreadMap) {
                    if ($row->contract_id === null) {
                        $this->generalUnread = (int) $row->cnt;
                    } else {
                        $unreadMap[(string) $row->contract_id] = (int) $row->cnt;
                    }
                });
        }

        $this->tenantContracts = Contract::where('tenant_id', $tenantId)
            ->with('room:id,code')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Contract $c) => [
                'id'         => (string) $c->id,
                'status'     => $c->status,
                'room_code'  => $c->room?->code,
                'start_date' => $c->start_date?->format('d/m/Y'),
                'end_date'   => $c->end_date?->format('d/m/Y'),
                'unread'     => $unreadMap[(string) $c->id] ?? 0,
            ])
            ->sortByDesc('unread')
            ->values()
            ->toArray();
    }
}

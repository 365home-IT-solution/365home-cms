<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Services;

use App\Models\User;
use App\Services\AdminNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\ChatConversation;
use Modules\Minihouse\App\Models\ChatMessage;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Tenant;

// Logic nghiệp vụ DUY NHẤT cho chat khách thuê <-> nhân viên — CHỈ dành cho khách ĐÃ KÝ HỢP ĐỒNG
// (có Tenant thật), dùng CHUNG bởi Portal web (session,
// Modules\Minihouse\Http\Controllers\Portal\ChatPortalController), Portal API (Sanctum,
// App\Http\Controllers\Api\Minihouse\Portal\ChatController), API admin
// (App\Http\Controllers\Api\Admin\Minihouse\ChatController) VÀ trang Filament (nhân viên,
// Modules\Minihouse\App\Filament\Pages\TenantChat) — CẢ 4 nơi phải cư xử giống hệt nhau, tránh lệch
// dữ liệu (đúng nguyên tắc TenantPortalService/InteractsWithTenantPortalData đã áp dụng cho phần
// hợp đồng/hoá đơn trong module này). Khách TIỀM NĂNG/chưa ký hợp đồng KHÔNG chat — chỉ gửi
// "yêu cầu liên hệ" qua App\Http\Controllers\Api\Minihouse\Public\RentalInquiryController, nhân
// viên tự gọi lại tư vấn, không có kênh chat 2 chiều nào trước khi có hợp đồng.
//
// Vẫn chia luồng tin nhắn theo TỪNG HỢP ĐỒNG (contract_id, mirror order_id của Home) — 1 khách thuê
// có thể có NHIỀU hợp đồng theo thời gian (chuyển phòng, gia hạn tạo hợp đồng mới...).
class MinihouseChatService
{
    public function __construct(private readonly MinihouseChatRealtimeService $realtime)
    {
    }

    // Lấy hoặc tạo conversation của khách thuê ĐÃ KÝ HỢP ĐỒNG (chat chỉ mở cho khách thuê thật, xem
    // trong Portal — khách TIỀM NĂNG/chưa thuê dùng form liên hệ RentalInquiryController, KHÔNG chat)
    // — building_id chốt theo hợp đồng ĐANG HIỆU LỰC tại thời điểm TẠO LẦN ĐẦU, không tự đổi lại nếu
    // khách sau này chuyển phòng/hết hợp đồng — 1 khách thuê chỉ có ĐÚNG 1 conversation trong suốt
    // vòng đời (unique tenant_id).
    public function conversationFor(Tenant $tenant): ChatConversation
    {
        $conversation = ChatConversation::where('tenant_id', $tenant->id)->first();

        if ($conversation) {
            return $conversation;
        }

        $activeContract = TenantPortalService::tenantContracts($tenant)
            ->firstWhere('status', \Modules\Minihouse\App\Models\Contract::STATUS_ACTIVE);

        return ChatConversation::create([
            'tenant_id'   => $tenant->id,
            'building_id' => $activeContract?->room?->building_id,
            'contract_id' => $activeContract?->id,
            'status'      => ChatConversation::STATUS_OPEN,
        ]);
    }

    // Luồng hợp đồng PHẢI thuộc đúng khách của hội thoại (đứng tên hoặc ở cùng) — mirror Home kiểm
    // tra đơn phải của đúng khách trước khi gắn order_code vào tin. null = luồng chung, luôn hợp lệ.
    public function contractBelongsToConversation(ChatConversation $conversation, ?int $contractId): bool
    {
        if ($contractId === null) {
            return true;
        }

        $tenant = $conversation->tenant;

        return $tenant !== null
            && TenantPortalService::tenantContracts($tenant)->contains('id', $contractId);
    }

    // Nhãn hiển thị của 1 luồng hợp đồng (phòng + toà) — dùng cho push/socket/danh sách luồng, để app
    // hiện "Phòng A-101 · Toà A" thay vì mã hợp đồng.
    public function contractLabel(?int $contractId): array
    {
        if ($contractId === null) {
            return ['room_code' => null, 'building_name' => null];
        }

        $contract = Contract::withoutGlobalScopes()
            ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes()])
            ->find($contractId);

        return [
            'room_code'     => $contract?->room?->code,
            'building_name' => $contract?->room?->building?->name,
        ];
    }

    // Danh sách luồng của 1 hội thoại: "chung" + từng hợp đồng của khách, kèm số tin CHƯA ĐỌC và tin
    // cuối của mỗi luồng — mirror /api/admin/chat/{id}/orders của Home. $side = phía đang xem:
    // 'tenant' đếm tin admin gửi chưa đọc, 'admin' đếm tin khách gửi chưa đọc.
    public function threads(ChatConversation $conversation, string $side): array
    {
        $fromSender = $side === 'tenant' ? ChatMessage::SENDER_ADMIN : ChatMessage::SENDER_TENANT;

        $unread = ChatMessage::where('conversation_id', $conversation->id)
            ->where('sender_type', $fromSender)
            ->whereNull('read_at')
            ->get(['contract_id'])
            ->groupBy(fn ($m) => (string) ($m->contract_id ?? ''))
            ->map->count();

        $lastByThread = ChatMessage::where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->get(['id', 'contract_id', 'body', 'created_at'])
            ->unique(fn ($m) => (string) ($m->contract_id ?? ''))
            ->keyBy(fn ($m) => (string) ($m->contract_id ?? ''));

        $row = function (?int $contractId, array $extra) use ($unread, $lastByThread) {
            $key  = (string) ($contractId ?? '');
            $last = $lastByThread->get($key);

            return array_merge([
                'contract_id'     => $contractId,
                'unread'          => (int) ($unread[$key] ?? 0),
                'last_message'    => $last ? mb_substr($last->body, 0, 100) : null,
                'last_message_at' => $last?->created_at?->toIso8601String(),
            ], $extra);
        };

        $threads = [$row(null, ['type' => 'general', 'title' => 'Hỗ trợ chung', 'room_code' => null, 'building_name' => null, 'status' => null])];

        if ($conversation->tenant) {
            foreach (TenantPortalService::tenantContracts($conversation->tenant)->sortByDesc('start_date') as $contract) {
                $label = $this->contractLabel($contract->id);

                $threads[] = $row($contract->id, [
                    'type'          => 'contract',
                    'title'         => trim(($label['room_code'] ?? 'Hợp đồng #' . $contract->id) . ($label['building_name'] ? ' · ' . $label['building_name'] : '')),
                    'room_code'     => $label['room_code'],
                    'building_name' => $label['building_name'],
                    'status'        => $contract->status,
                ]);
            }
        }

        return $threads;
    }

    // Tổng số tin chưa đọc theo phía xem — KHÔNG đánh dấu đã đọc, dùng cho badge trên app.
    public function unreadCount(ChatConversation $conversation, string $side): int
    {
        return $side === 'tenant' ? (int) $conversation->tenant_unread : (int) $conversation->admin_unread;
    }

    // $contractId: null = luồng "hỗ trợ chung", khác null = luồng riêng của đúng hợp đồng đó — mirror
    // App\Http\Controllers\Api\ChatController của Home (order_id).
    public function recentMessages(ChatConversation $conversation, ?int $contractId = null, int $limit = 20): array
    {
        $query = ChatMessage::where('conversation_id', $conversation->id)
            ->when($contractId === null, fn ($q) => $q->whereNull('contract_id'), fn ($q) => $q->where('contract_id', $contractId));

        $total = (clone $query)->count();

        $messages = $query->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        return [
            'messages' => $messages->map(fn (ChatMessage $m) => $this->formatMessage($m))->all(),
            'has_more' => $total > $limit,
        ];
    }

    public function olderMessages(ChatConversation $conversation, ?string $beforeId, ?int $contractId = null, int $limit = 20): array
    {
        $limit = min($limit, 50);

        $query = ChatMessage::where('conversation_id', $conversation->id)
            ->when($contractId === null, fn ($q) => $q->whereNull('contract_id'), fn ($q) => $q->where('contract_id', $contractId));

        if ($beforeId) {
            $query->where('id', '<', $beforeId);
        }

        $rows    = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;

        return [
            'messages' => $rows->take($limit)->reverse()->values()->map(fn (ChatMessage $m) => $this->formatMessage($m))->all(),
            'has_more' => $hasMore,
        ];
    }

    // $senderName chỉ cần khi gửi thay mặt admin (Tenant thì FE tự biết tên chính mình, không cần
    // server trả lại) — cùng quy ước formatMessage() của Home.
    public function send(ChatConversation $conversation, string $senderType, string $senderId, string $body, ?string $senderName = null, ?int $contractId = null): ChatMessage
    {
        $body = trim($body);

        $message = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'contract_id'     => $contractId,
            'sender_type'     => $senderType,
            'sender_id'       => $senderId,
            'body'            => $body,
        ]);

        $preview = mb_substr($body, 0, 100);
        $isFromTenant = $senderType === ChatMessage::SENDER_TENANT;

        $conversation->update([
            'last_message_preview' => $preview,
            'last_message_at'      => $message->created_at,
            'admin_unread'         => $isFromTenant ? $conversation->admin_unread + 1 : 0,
            'tenant_unread'        => $isFromTenant ? 0 : $conversation->tenant_unread + 1,
            // Con trỏ luồng đang trao đổi gần nhất — mirror Home cập nhật order_id khi khách nhắn theo đơn.
            'contract_id'          => $contractId ?? $conversation->contract_id,
        ]);

        $payload = $this->formatMessage($message, $senderName);

        $this->realtime->broadcastMessage($conversation->id, $payload);

        if ($isFromTenant) {
            $this->realtime->notifyAdminList(
                $conversation->id,
                $preview,
                $message->created_at->toIso8601String(),
                $conversation->admin_unread,
                $conversation->tenant ? ['id' => $conversation->tenant->id, 'fullname' => $conversation->tenant->fullname, 'phone' => $conversation->tenant->phone] : []
            );

            $this->notifyStaff($conversation, $preview);
        } else {
            $this->notifyTenant($conversation, $preview, $contractId, $senderName);
        }

        return $message;
    }

    // Nhân viên nhắn → báo khách thuê qua 3 kênh: (1) socket cá nhân (app đang mở, cập nhật badge
    // ngoài khung chat), (2) LƯU LẠI 1 dòng PortalNotification (để khách xem lại được trong danh sách
    // "Thông báo" nếu lỡ push — trước đây gọi thẳng FcmService, KHÔNG lưu gì cả, đúng lỗ hổng đã tự
    // phát hiện và sửa), (3) push FCM/Expo kể cả khi app đóng — mirror ĐÚNG hành vi
    // Api\Admin\ChatController::send() của Home (NotificationFcmService::sendToCustomer() cũng vừa
    // lưu DB vừa push, gói data có notification_id/unread_count). Lỗi kênh nào cũng KHÔNG chặn việc
    // gửi tin (tin đã lưu + đã bắn vào khung chat) — PortalNotificationService tự nuốt lỗi push.
    private function notifyTenant(ChatConversation $conversation, string $preview, ?int $contractId, ?string $senderName): void
    {
        $tenant = $conversation->tenant;

        if (! $tenant) {
            return;
        }

        $label = $this->contractLabel($contractId);

        $this->realtime->notifyTenant($tenant->id, [
            'type'            => 'message',
            'conversation_id' => $conversation->id,
            'contract_id'     => $contractId,
            'room_code'       => $label['room_code'],
            'building_name'   => $label['building_name'],
            'preview'         => $preview,
            'sender_name'     => $senderName,
            'tenant_unread'   => $conversation->tenant_unread,
        ]);

        PortalNotificationService::notify(
            $tenant,
            'minihouse_message',
            $senderName ? 'Tin nhắn từ ' . $senderName : 'Tin nhắn từ chủ nhà',
            $preview,
            route('minihouse.portal.chat.show', $contractId !== null ? ['contract_id' => $contractId] : []),
            [
                'conversation_id' => (string) $conversation->id,
                'contract_id'     => $contractId === null ? '' : (string) $contractId,
                'room_code'       => (string) ($label['room_code'] ?? ''),
            ],
        );
    }

    public function markReadByTenant(ChatConversation $conversation): void
    {
        if ($conversation->tenant_unread === 0) {
            return;
        }

        $conversation->update(['tenant_unread' => 0]);

        ChatMessage::where('conversation_id', $conversation->id)
            ->where('sender_type', ChatMessage::SENDER_ADMIN)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);

        $this->realtime->broadcastRead($conversation->id, 'tenant');

        if ($conversation->tenant_id) {
            $this->realtime->notifyTenant($conversation->tenant_id, [
                'type'            => 'read',
                'conversation_id' => $conversation->id,
                'tenant_unread'   => 0,
            ]);
        }
    }

    public function markReadByAdmin(ChatConversation $conversation): void
    {
        if ($conversation->admin_unread === 0) {
            return;
        }

        $conversation->update(['admin_unread' => 0]);

        ChatMessage::where('conversation_id', $conversation->id)
            ->where('sender_type', ChatMessage::SENDER_TENANT)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);

        $this->realtime->broadcastRead($conversation->id, 'admin');
    }

    public function formatMessage(ChatMessage $message, ?string $senderName = null): array
    {
        return [
            'id'          => $message->id,
            'contract_id' => $message->contract_id,
            'sender_type' => $message->sender_type,
            'sender_id'   => $message->sender_id,
            'sender_name' => $senderName,
            'body'        => $message->body,
            'read_at'     => $message->read_at?->toIso8601String(),
            'time'        => $message->created_at->format('H:i'),
            'created_at'  => $message->created_at->toIso8601String(),
        ];
    }

    // Nhân viên nên nhận thông báo tin nhắn mới của toà nhà này: super_admin luôn nhận; tài khoản
    // thường nhận nếu building_id nằm trong rootBuildingIds() của họ (đã tự bao gồm "chưa gán toà
    // nào = xem được hết", xem User::rootBuildingIds()). MiniHouse không có khái niệm "chủ đối tác
    // bị loại khỏi thông báo tin nhắn" như Home (excludePartnerOwners()) — không áp dụng ở đây.
    private function notifyStaff(ChatConversation $conversation, string $preview): void
    {
        try {
            $buildingId = $conversation->building_id;

            $recipients = User::query()
                ->where(fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', config('filament-shield.super_admin.name')))
                    ->orWhere('partner_id', '!=', null))
                ->get()
                ->filter(fn (User $u) => $u->isSuperAdmin() || ($buildingId && in_array($buildingId, $u->rootBuildingIds(), true)))
                ->values();

            if ($recipients->isEmpty()) {
                return;
            }

            app(AdminNotificationService::class)->notify(
                $recipients,
                'Tin nhắn khách thuê',
                ($conversation->tenant?->fullname ?? 'Khách thuê') . ': ' . $preview,
                ['type' => 'minihouse_message', 'conversation_id' => $conversation->id, 'module' => 'minihouse'],
                'heroicon-o-chat-bubble-left-right',
                'primary',
            );
        } catch (\Throwable $e) {
            Log::warning('MinihouseChatService: notify staff failed', [
                'conversation_id' => $conversation->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }
}

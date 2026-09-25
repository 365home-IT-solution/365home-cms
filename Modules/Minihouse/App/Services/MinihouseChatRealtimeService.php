<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Mirror App\Services\ChatRealtimeService (chat Home) — CÙNG server Node (websocket/server.js),
// nhưng dùng bộ phòng/endpoint RIÊNG (tiền tố "mh-chat" thay vì "chat") để tách hẳn khỏi chat Home,
// tránh admin đang mở màn hình chat Home nhận nhầm tín hiệu list-update của MiniHouse (2 nghiệp vụ
// khác hẳn nhau dù dùng chung hạ tầng Socket.IO) — xem websocket/server.js phần "MiniHouse chat".
class MinihouseChatRealtimeService
{
    public function broadcastMessage(string $conversationId, array $message): void
    {
        $this->post('/internal/mh-chat-message', [
            'conversation_id' => $conversationId,
            'message'         => $message,
        ]);
    }

    public function notifyAdminList(
        string $conversationId,
        string $preview,
        string $lastMessageAt,
        int    $adminUnread,
        array  $tenant
    ): void {
        $this->post('/internal/mh-chat-list-update', [
            'conversation_id'      => $conversationId,
            'last_message_preview' => $preview,
            'last_message_at'      => $lastMessageAt,
            'admin_unread'         => $adminUnread,
            'tenant'               => $tenant,
        ]);
    }

    // Báo riêng cho khách thuê (kênh cá nhân, mọi thiết bị đang kết nối app) — mirror /internal/notify
    // của khách Home: để app cập nhật badge chưa đọc ở màn khác NGOÀI khung chat đang mở.
    public function notifyTenant(int $tenantId, array $payload): void
    {
        $this->post('/internal/mh-chat-tenant-notify', [
            'tenant_id' => $tenantId,
            'payload'   => $payload,
        ]);
    }

    public function broadcastRead(string $conversationId, string $readBy): void
    {
        $this->post('/internal/mh-chat-read', [
            'conversation_id' => $conversationId,
            'read_by'         => $readBy, // 'tenant' | 'admin'
        ]);
    }

    private function post(string $path, array $payload): void
    {
        $url = rtrim(config('services.websocket.url', 'http://localhost:3001'), '/');
        $key = config('services.websocket.internal_key', '');

        if (empty($url)) {
            return;
        }

        try {
            Http::withHeaders(['x-internal-key' => $key])
                ->timeout(2)
                ->post("{$url}{$path}", $payload);
        } catch (\Throwable $e) {
            Log::warning("WS minihouse chat push failed [{$path}]", [
                'conversation_id' => $payload['conversation_id'] ?? null,
                'error'           => $e->getMessage(),
            ]);
        }
    }
}

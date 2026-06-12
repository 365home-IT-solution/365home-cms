<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatRealtimeService
{
    /**
     * Broadcast tin nhắn mới vào conversation channel.
     * Cả khách lẫn admin đang xem conversation đều nhận được.
     */
    public function broadcastMessage(string $conversationId, array $message): void
    {
        $this->post('/internal/chat-message', [
            'conversation_id' => $conversationId,
            'message'         => $message,
        ]);
    }

    /**
     * Broadcast cập nhật danh sách conversation cho admin.
     * Admin đang xem màn hình danh sách sẽ thấy conversation dịch lên đầu và unread count.
     */
    public function notifyAdminList(
        string $conversationId,
        string $preview,
        string $lastMessageAt,
        int    $adminUnread,
        array  $customer
    ): void {
        $this->post('/internal/chat-list-update', [
            'conversation_id'      => $conversationId,
            'last_message_preview' => $preview,
            'last_message_at'      => $lastMessageAt,
            'admin_unread'         => $adminUnread,
            'customer'             => $customer,
        ]);
    }

    /**
     * Broadcast sự kiện đã đọc vào conversation channel.
     * Phía còn lại nhận được để cập nhật dấu tick "đã xem".
     */
    public function broadcastRead(string $conversationId, string $readBy): void
    {
        $this->post('/internal/chat-read', [
            'conversation_id' => $conversationId,
            'read_by'         => $readBy, // 'customer' | 'admin'
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
            Log::warning("WS chat push failed [{$path}]", [
                'conversation_id' => $payload['conversation_id'] ?? null,
                'error'           => $e->getMessage(),
            ]);
        }
    }
}

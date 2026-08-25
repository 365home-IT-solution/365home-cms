<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Đẩy realtime tín hiệu "có thông báo mới" cho admin qua server Socket.IO độc lập
 * (websocket/server.js, cùng cơ chế với App\Services\ChatRealtimeService) — KHÔNG gửi trực tiếp nội
 * dung để hiển thị, chỉ báo hiệu để client tự gọi lại GET /api/admin/notifications làm mới danh
 * sách (tránh lệch dữ liệu nếu client cache khác với DB, và khỏi phải định nghĩa lại đúng logic ai
 * được xem thông báo nào ở tầng Node).
 *
 * Broadcast vào phòng chung 'admin:notifications' (client tự subscribe qua sự kiện
 * 'subscribe:admin-notifications') — không phân biệt admin nào nhận thông báo nào (khác với
 * OrderObserver::adminUsers() ở tầng DB, nơi mới thực sự lọc đúng người xem được) vì mục đích chỉ
 * là "làm mới", REST API vẫn tự lọc đúng theo user khi client gọi lại.
 */
class AdminNotificationRealtimeService
{
    public function broadcastNew(array $notification): void
    {
        $url = rtrim(config('services.websocket.url', 'http://localhost:3001'), '/');
        $key = config('services.websocket.internal_key', '');

        if (empty($url)) {
            return;
        }

        try {
            Http::withHeaders(['x-internal-key' => $key])
                ->timeout(2)
                ->post("{$url}/internal/admin-notify", ['notification' => $notification]);
        } catch (\Throwable $e) {
            Log::warning('WS admin-notify push failed', ['error' => $e->getMessage()]);
        }
    }
}

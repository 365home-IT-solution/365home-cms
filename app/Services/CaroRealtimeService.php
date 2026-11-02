<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Đẩy sự kiện caro tới kênh cá nhân của khách trên WS server (websocket/server.js, /internal/caro-event). Đẩy lỗi chỉ log —
// app vẫn có thể hỏi lại trạng thái trận qua GET /api/games/caro/matches/{id}.
class CaroRealtimeService
{
    public function push(string $customerId, string $event, array $payload): void
    {
        $url = rtrim((string) config('services.websocket.url', 'http://localhost:3001'), '/');
        if ($url === '') {
            return;
        }

        try {
            Http::withHeaders(['x-internal-key' => config('services.websocket.internal_key', '')])
                ->timeout(2)
                ->post("{$url}/internal/caro-event", [
                    'customer_id' => $customerId,
                    'event'       => $event,
                    'payload'     => $payload,
                ]);
        } catch (\Throwable $e) {
            Log::warning('WS caro-event push failed', ['customer_id' => $customerId, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }
}

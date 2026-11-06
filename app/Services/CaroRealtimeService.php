<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Đẩy sự kiện caro tới kênh cá nhân của khách trên WS server (websocket/server.js). Đẩy lỗi chỉ log — app vẫn có thể hỏi lại trạng thái
// trận qua GET /api/games/caro/matches/{id}.
// Để nước đi không phải chờ WS: sự kiện được gom lại và gửi 1 lần (/internal/caro-events) SAU KHI đã trả response cho app
// (app()->terminating, PHP-FPM đã đóng kết nối với app trước đó). Payload truyền dạng closure để cả việc dựng trạng thái trận cũng chạy sau.
// Đăng ký singleton (AppServiceProvider) để mọi chỗ trong 1 request dùng chung hàng đợi.
class CaroRealtimeService
{
    /** @var array<int, array{customer_id: string, event: string, payload: array|\Closure}> */
    private array $pending = [];

    private bool $flushRegistered = false;

    /** @param array|\Closure(): array $payload */
    public function push(string $customerId, string $event, array|\Closure $payload): void
    {
        $this->pending[] = ['customer_id' => $customerId, 'event' => $event, 'payload' => $payload];

        if (! $this->flushRegistered) {
            $this->flushRegistered = true;
            app()->terminating(fn () => $this->flush());
        }
    }

    // Gửi hết sự kiện đang chờ trong 1 request. WS server cũ chưa có /internal/caro-events thì gửi lần lượt qua /internal/caro-event.
    public function flush(): void
    {
        $events               = $this->pending;
        $this->pending        = [];
        $this->flushRegistered = false;

        $url = rtrim((string) config('services.websocket.url', 'http://localhost:3001'), '/');
        if ($events === [] || $url === '') {
            return;
        }

        $events = array_map(function (array $e) {
            try {
                $e['payload'] = $e['payload'] instanceof \Closure ? ($e['payload'])() : $e['payload'];
            } catch (\Throwable $ex) {
                Log::warning('Caro: dựng payload WS thất bại', ['customer_id' => $e['customer_id'], 'event' => $e['event'], 'error' => $ex->getMessage()]);
                $e['payload'] = null;
            }

            return $e;
        }, $events);
        $events = array_values(array_filter($events, fn (array $e) => $e['payload'] !== null));

        $http = fn () => Http::withHeaders(['x-internal-key' => config('services.websocket.internal_key', '')])->timeout(2);

        try {
            $response = $http()->post("{$url}/internal/caro-events", ['events' => $events]);
            if ($response->status() !== 404) {
                return;
            }

            foreach ($events as $event) {
                $http()->post("{$url}/internal/caro-event", $event);
            }
        } catch (\Throwable $e) {
            Log::warning('WS caro-events push failed', ['count' => count($events), 'error' => $e->getMessage()]);
        }
    }
}

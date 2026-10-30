<?php

declare(strict_types=1);

namespace App\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;

/**
 * Cầu dao cho các lệnh đẩy realtime tới WS server nội bộ (services.websocket.url, các route /internal/...).
 *
 * Mỗi lệnh đẩy có timeout 2 giây; một thao tác như xoá nhiều đơn bắn 3 lệnh đẩy cho mỗi đơn, nên khi WS server không
 * kết nối được (máy local không chạy WS, WS đang restart...) request cộng dồn chờ tới mức vượt max_execution_time → 500.
 * Lỗi KẾT NỐI đầu tiên ngắt cầu dao: các lệnh đẩy tới WS trong COOLDOWN giây sau đó bị từ chối ngay (ConnectException,
 * nơi gọi vốn đã try/catch và chỉ log warning), không chờ timeout nữa. Hết cooldown thì thử lại bình thường.
 * Chỉ áp cho host:port của WS server, không đụng tới các request HTTP khác.
 */
class WsCircuitBreaker
{
    private const COOLDOWN_SECONDS = 30;

    private static ?float $openUntil = null;

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            if (! $this->isWsRequest($request)) {
                return $handler($request, $options);
            }

            if (self::$openUntil !== null && microtime(true) < self::$openUntil) {
                return Create::rejectionFor(new ConnectException('WS server không kết nối được (cầu dao đang ngắt, bỏ qua lệnh đẩy).', $request));
            }

            return $handler($request, $options)->then(
                function ($response) {
                    self::$openUntil = null;

                    return $response;
                },
                function ($reason) {
                    if ($reason instanceof ConnectException) {
                        self::$openUntil = microtime(true) + self::COOLDOWN_SECONDS;
                    }

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    private function isWsRequest(RequestInterface $request): bool
    {
        $ws = parse_url((string) config('services.websocket.url', ''));

        if (empty($ws['host'])) {
            return false;
        }

        $uri = $request->getUri();

        return $uri->getHost() === $ws['host']
            && ($uri->getPort() ?? null) === ($ws['port'] ?? null)
            && str_starts_with($uri->getPath(), '/internal/');
    }
}

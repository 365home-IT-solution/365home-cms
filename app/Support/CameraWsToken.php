<?php

declare(strict_types=1);

namespace App\Support;

// Ký token ngắn hạn cho trình duyệt kết nối WebSocket sang Node proxy (websocket/server.js,
// endpoint /camera-proxy) — KHÔNG dùng session Laravel (WebSocket của Node là 1 tiến trình/miền
// cổng khác, không tự thấy cookie phiên Filament). Token gói sẵn stream_key + base_url Frigate,
// Node chỉ cần xác minh chữ ký bằng CHUNG 1 khoá bí mật với Laravel (tái dùng
// config('services.websocket.internal_key') — khoá này Node đã biết sẵn để gọi ngược
// /internal/frigate-session, không cần thêm biến môi trường mới phải đồng bộ 2 nơi).
//
// partner_id — MỖI ĐỐI TÁC giờ có thể dùng 1 server Frigate riêng (App\Models\CameraSetting): Node
// đã tự dùng ĐÚNG base_url trong token để mở kết nối upstream (không đổi), nhưng bước XIN COOKIE
// PHIÊN (/internal/frigate-session) trước đó KHÔNG biết đăng nhập vào server nào nếu thiếu định
// danh này — ký kèm partner_id để Laravel resolve đúng CameraSetting/FrigateSessionClient của
// server Frigate mà camera đó thuộc về.
class CameraWsToken
{
    public static function issue(string $streamKey, string $baseUrl, string $partnerId, int $ttlSeconds = 60): string
    {
        $payload = base64_encode(json_encode([
            'stream_key' => $streamKey,
            'base_url'   => $baseUrl,
            'partner_id' => $partnerId,
            'exp'        => time() + $ttlSeconds,
        ]));

        $signature = hash_hmac('sha256', $payload, self::secret());

        return $payload . '.' . $signature;
    }

    private static function secret(): string
    {
        return (string) config('services.websocket.internal_key');
    }
}

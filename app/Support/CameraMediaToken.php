<?php

declare(strict_types=1);

namespace App\Support;

// Ký token ngắn hạn cho việc phát lại lịch sử (HLS) qua CameraMediaProxyController — KHÁC
// CameraWsToken (dùng cho Node xác minh, khác tiến trình) ở chỗ token này được XÁC MINH NGAY TRONG
// LARAVEL (cùng tiến trình phát hành), nên dùng luôn APP_KEY làm khoá bí mật thay vì khoá dùng
// chung với Node — không có lý do gì để lộ khoá nội bộ Node/Laravel ra 1 tính năng không liên quan.
//
// Token chỉ gói "thư mục" VOD trên Frigate (KHÔNG kèm tên file) — vì 1 phiên phát lại HLS gồm NHIỀU
// request liên tiếp tới các file khác nhau trong CÙNG 1 thư mục (master.m3u8 → index.m3u8 →
// nhiều đoạn .ts/.m4s, trình duyệt tự suy đường dẫn tương đối từ nội dung .m3u8) — ký cố định
// "thư mục", để tên file là tham số route RIÊNG không nằm trong chữ ký, tránh phải cấp lại token
// cho từng file lẻ trong cùng 1 phiên xem.
class CameraMediaToken
{
    // $transcode=true: đoạn ghi hình này là H.265/HEVC (trình duyệt desktop không giải mã được qua
    // MSE) — CameraMediaProxyController sẽ chuyển mã bằng ffmpeg thay vì chuyển tiếp nguyên văn HLS.
    // Quyết định này đưa ra 1 LẦN lúc phát hành token (đã kiểm tra codec thật, xem
    // CameraRecordingService::playbackUrl()), không kiểm tra lại mỗi request để đỡ tốn 1 lượt gọi
    // Frigate cho mỗi file .ts/.m4s trong cùng 1 phiên xem.
    public static function issue(string $frigatePathPrefix, int $ttlSeconds = 3600, bool $transcode = false): string
    {
        $payload = base64_encode(json_encode([
            'path'      => $frigatePathPrefix,
            'exp'       => time() + $ttlSeconds,
            'transcode' => $transcode,
        ]));

        $signature = hash_hmac('sha256', $payload, self::secret());

        return $payload . '.' . $signature;
    }

    /**
     * @return array{path: string, transcode: bool}|null null nếu token sai chữ ký/hết hạn.
     */
    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $signature] = $parts;

        if (! hash_equals(hash_hmac('sha256', $payload, self::secret()), $signature)) {
            return null;
        }

        $decoded = json_decode((string) base64_decode($payload, true), true);

        if (! is_array($decoded) || ! isset($decoded['path'], $decoded['exp'])) {
            return null;
        }

        if ((int) $decoded['exp'] < time()) {
            return null;
        }

        return [
            'path'      => (string) $decoded['path'],
            'transcode' => (bool) ($decoded['transcode'] ?? false),
        ];
    }

    private static function secret(): string
    {
        return (string) config('app.key');
    }
}

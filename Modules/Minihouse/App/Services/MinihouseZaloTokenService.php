<?php

namespace Modules\Minihouse\App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\ZaloSetting;

// Refresh access_token/refresh_token cho Zalo OA RIÊNG của MiniHouse — mirror đúng cơ chế
// App\Services\ZaloTokenService (khoá Cache::lock() khi refresh vì refresh_token của Zalo chỉ dùng
// được 1 lần, ghi lại vào DB làm lớp lưu bền vững phía sau Cache) nhưng đọc/ghi ZaloSetting riêng —
// KHÔNG đụng Cache key hay bảng settings của Home, tránh 2 Zalo OA khác nhau giẫm lên nhau.
class MinihouseZaloTokenService
{
    private const CACHE_ACCESS_TOKEN  = 'minihouse_zalo_access_token';
    private const CACHE_REFRESH_TOKEN = 'minihouse_zalo_refresh_token';
    private const LOCK_KEY            = 'minihouse_zalo_token_refresh';

    public function getAccessToken(): string
    {
        $cached = Cache::get(self::CACHE_ACCESS_TOKEN);

        if ($cached) {
            return $cached;
        }

        return Cache::lock(self::LOCK_KEY, 10)->block(5, function () {
            $cached = Cache::get(self::CACHE_ACCESS_TOKEN);

            if ($cached) {
                return $cached;
            }

            $settings = ZaloSetting::current();

            if (
                $settings->access_token
                && $settings->access_token_expires_at
                && $settings->access_token_expires_at->isFuture()
            ) {
                Cache::put(self::CACHE_ACCESS_TOKEN, $settings->access_token, $settings->access_token_expires_at);

                return $settings->access_token;
            }

            return $this->refresh($settings);
        });
    }

    private function refresh(ZaloSetting $settings): string
    {
        $refreshToken = Cache::get(self::CACHE_REFRESH_TOKEN) ?? $settings->refresh_token;

        if (! $settings->app_id || ! $settings->app_secret || ! $refreshToken) {
            throw new \RuntimeException('Chưa cấu hình đủ Zalo OA cho MiniHouse (App ID/App Secret/Refresh Token) — vào mục Cấu hình Zalo để thêm.');
        }

        $data = $this->callZaloRefresh($settings->app_id, $settings->app_secret, $refreshToken);

        if (! isset($data['access_token'])) {
            Log::critical('MinihouseZaloTokenService: refresh token thất bại', ['response' => $data]);

            throw new \RuntimeException('Không thể làm mới access token Zalo (MiniHouse): ' . ($data['error_description'] ?? json_encode($data)));
        }

        $expiresIn = $data['expires_in'] ?? 3600;
        $expiresAt = now()->addSeconds($expiresIn);

        Cache::put(self::CACHE_ACCESS_TOKEN, $data['access_token'], $expiresAt);

        if (isset($data['refresh_token'])) {
            Cache::put(self::CACHE_REFRESH_TOKEN, $data['refresh_token'], now()->addMonths(3));
        }

        $settings->access_token = $data['access_token'];
        $settings->access_token_expires_at = $expiresAt;

        if (isset($data['refresh_token'])) {
            $settings->refresh_token = $data['refresh_token'];
        }

        $settings->save();

        return $data['access_token'];
    }

    // Zalo THU HỒI refresh_token cũ ngay khi nhận được request refresh, kể cả khi app không nhận
    // được phản hồi hợp lệ (mất mạng/timeout giữa chừng) — 1 lần gọi bị lỗi mạng là refresh_token
    // "chết" vĩnh viễn, không có cách nào tự lấy lại được nữa ngoài dán tay token mới (đã gặp thật,
    // xem log 2026-09-11: "response": null ngay trước chuỗi "Invalid refresh token." liên tục sau
    // đó). Thử lại vài lần khi gặp lỗi KẾT NỐI/phản hồi không đọc được JSON (rất có thể request CHƯA
    // TỚI được Zalo) — KHÔNG thử lại khi Zalo đã trả lời rõ ràng là từ chối (có error_name/error
    // trong body), vì lúc đó chắc chắn token đã bị thu hồi thật, thử lại vô ích.
    private function callZaloRefresh(?string $appId, ?string $appSecret, string $refreshToken): ?array
    {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::timeout(10)
                    ->asForm()
                    ->withHeaders(['secret_key' => $appSecret])
                    ->post('https://oauth.zaloapp.com/v4/oa/access_token', [
                        'app_id'        => $appId,
                        'grant_type'    => 'refresh_token',
                        'refresh_token' => $refreshToken,
                    ]);

                $data = $response->json();
            } catch (ConnectionException $e) {
                $data = null;
            }

            $isDefiniteRejection = is_array($data) && (isset($data['error']) || isset($data['error_name']));

            if (isset($data['access_token']) || $isDefiniteRejection || $attempt === $maxAttempts) {
                return $data;
            }

            Log::warning('MinihouseZaloTokenService: refresh không nhận được phản hồi hợp lệ, thử lại', [
                'attempt' => $attempt,
                'response' => $data,
            ]);

            usleep(700_000 * $attempt); // 0.7s, rồi 1.4s trước lần thử kế tiếp
        }

        return null;
    }
}

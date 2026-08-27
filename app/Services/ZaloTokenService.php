<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Nguồn refresh access_token/refresh_token DUY NHẤT cho Zalo OA — dùng chung bởi
 * ZaloOtpService (OTP đăng nhập) và ZaloZnsService (thông báo đặt phòng), vì cả 2
 * cùng chia sẻ 1 Zalo OA. Refresh_token của Zalo chỉ dùng được đúng 1 lần (dùng
 * xong bị Zalo thu hồi, cấp token mới) nên bắt buộc phải khoá khi refresh — nếu để
 * 2 service tự refresh độc lập như trước, 2 tiến trình có thể cùng đọc 1
 * refresh_token cũ và một bên sẽ bị Zalo từ chối.
 */
class ZaloTokenService
{
    public function getAccessToken(): string
    {
        $cached = Cache::get('zalo_access_token');
        if ($cached) {
            return $cached;
        }

        return Cache::lock('zalo_token_refresh', 10)->block(5, function () {
            // Tiến trình vừa đợi lock có thể đã có token mới do tiến trình giữ lock
            // trước đó refresh xong — kiểm tra lại trước khi tự refresh thêm lần nữa.
            $cached = Cache::get('zalo_access_token');
            if ($cached) {
                return $cached;
            }

            return $this->refresh();
        });
    }

    private function refresh(): string
    {
        $refreshToken = Cache::get('zalo_refresh_token') ?? config('zalo.refresh_token');

        if (! $refreshToken) {
            throw new \RuntimeException('Zalo refresh_token chưa được cấu hình.');
        }

        $response = Http::asForm()
            ->withHeaders(['secret_key' => config('zalo.app_secret')])
            ->post(config('zalo.oauth_url'), [
                'app_id'        => config('zalo.app_id'),
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

        $data = $response->json();

        if (! isset($data['access_token'])) {
            Log::critical('Zalo token refresh failed', ['response' => $data]);
            throw new \RuntimeException('Không thể làm mới access token Zalo: ' . ($data['error_description'] ?? json_encode($data)));
        }

        $expiresIn = $data['expires_in'] ?? 3600;
        Cache::put('zalo_access_token', $data['access_token'], now()->addSeconds($expiresIn));

        if (isset($data['refresh_token'])) {
            Cache::put('zalo_refresh_token', $data['refresh_token'], now()->addMonths(3));
        }

        return $data['access_token'];
    }
}

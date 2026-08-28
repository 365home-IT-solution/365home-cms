<?php

namespace App\Services;

use App\Settings\ZaloSettings;
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
 *
 * Cache (Redis/file) là nơi đọc NHANH cho mọi request, nhưng KHÔNG bền vững — nếu cache bị mất
 * (Redis container bị tạo lại, VPS restart...) mà refresh_token chỉ sống ở đó thì mất luôn, vì
 * refresh_token của Zalo dùng 1 lần nên .env không tự phục hồi được. ZaloSettings (bảng `settings`,
 * mã hoá — cùng cơ chế MailSettings đang dùng) là lớp lưu BỀN VỮNG phía sau Cache: mọi lần refresh
 * thành công đều ghi xuống đây, và khi Cache trống sẽ đọc lại từ đây trước khi phải tự refresh mới.
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

            // Cache trống nhưng DB vẫn còn access_token chưa hết hạn (vd Redis vừa mất dữ liệu
            // ngay sau deploy) — dùng lại luôn, không cần gọi Zalo, đồng thời nạp lại vào Cache.
            $settings = $this->loadSettingsSafely();
            if (
                $settings
                && $settings->access_token
                && $settings->access_token_expires_at
                && $settings->access_token_expires_at > now()->timestamp
            ) {
                $ttl = $settings->access_token_expires_at - now()->timestamp;
                Cache::put('zalo_access_token', $settings->access_token, now()->addSeconds($ttl));

                return $settings->access_token;
            }

            return $this->refresh($settings);
        });
    }

    // ZaloSettings đọc/giải mã từ bảng `settings` — nếu APP_KEY hiện tại không khớp với lúc mã hoá
    // (từng gặp đúng lỗi này với MailSettings, xem ManageMail.php) sẽ ném DecryptException. KHÔNG
    // được để lỗi đó làm sập luôn cả luồng gửi OTP — chỉ log rồi coi như không có dữ liệu bền vững,
    // để service tự rơi về Cache/refresh_token trong .env như hành vi cũ.
    private function loadSettingsSafely(): ?ZaloSettings
    {
        try {
            return app(ZaloSettings::class);
        } catch (\Throwable $e) {
            Log::error('ZaloTokenService: không đọc được ZaloSettings, bỏ qua lớp lưu bền vững', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function refresh(?ZaloSettings $settings = null): string
    {
        $settings ??= $this->loadSettingsSafely();
        $refreshToken = Cache::get('zalo_refresh_token') ?? $settings?->refresh_token ?? config('zalo.refresh_token');

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

        if ($settings) {
            try {
                $settings->access_token = $data['access_token'];
                $settings->access_token_expires_at = now()->addSeconds($expiresIn)->timestamp;
                if (isset($data['refresh_token'])) {
                    $settings->refresh_token = $data['refresh_token'];
                }
                $settings->save();
            } catch (\Throwable $e) {
                // Token vẫn dùng được ngay (đã cache ở trên) — chỉ mất lớp lưu bền vững lần này,
                // không được để lỗi ghi DB làm hỏng cả request đang gửi OTP.
                Log::error('ZaloTokenService: không lưu được ZaloSettings', ['message' => $e->getMessage()]);
            }
        }

        return $data['access_token'];
    }
}

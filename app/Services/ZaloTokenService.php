<?php

namespace App\Services;

use App\Settings\ZaloSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Nguồn refresh access_token/refresh_token DUY NHẤT cho Zalo OA — dùng chung bởi
 * ZaloOtpService (OTP đăng nhập), ZaloZnsService (thông báo đặt phòng), VÀ
 * Modules\Minihouse\App\Services\MinihouseZaloTokenService (MiniHouse dùng CHUNG đúng 1 Zalo OA
 * với Home, xem lịch sử sửa 2026-09-22 "hợp nhất về đúng 1 nơi quản lý token" — trước đó MiniHouse
 * tự giữ 1 bản refresh_token riêng, 2 nơi độc lập giẫm chân nhau làm cả 2 bên lỗi liên tục). Vì cả
 * 2 hệ thống cùng chia sẻ 1 Zalo OA. Refresh_token của Zalo chỉ dùng được đúng 1 lần (dùng
 * xong bị Zalo thu hồi, cấp token mới) nên bắt buộc phải khoá khi refresh — nếu để
 * 2 tiến trình tự refresh độc lập, có thể cùng đọc 1
 * refresh_token cũ và một bên sẽ bị Zalo từ chối.
 *
 * Cache (Redis/file) là nơi đọc NHANH cho mọi request, nhưng KHÔNG bền vững — nếu cache bị mất
 * (Redis container bị tạo lại, VPS restart...) mà refresh_token chỉ sống ở đó thì mất luôn, vì
 * refresh_token của Zalo dùng 1 lần nên .env không tự phục hồi được. ZaloSettings (bảng `settings`,
 * mã hoá — cùng cơ chế MailSettings đang dùng) là lớp lưu BỀN VỮNG phía sau Cache: mọi lần refresh
 * thành công đều ghi xuống đây, và khi Cache trống sẽ đọc lại từ đây trước khi phải tự refresh mới.
 *
 * BUG THẬT đã gặp (2026-09-22, log "Access token invalid" -124 ngay sau khi hợp nhất MiniHouse vào
 * đây): Cache::lock() chỉ loại trừ lẫn nhau đúng nghĩa NẾU mọi tiến trình gọi hàm này dùng CHUNG 1
 * backend cache — web/queue/cron/MiniHouse có thể chạy ở container/tiến trình khác nhau, nếu
 * CACHE_DRIVER không thật sự dùng chung (file/array riêng từng container) thì lock không có tác
 * dụng xuyên container: 2 bên cùng refresh gần lúc nhau, bên sau cầm access_token/refresh_token đã
 * bị Zalo thu hồi bởi bên trước. Đổi sang khoá THẬT ở tầng DB (lockForUpdate() ngay trên bảng
 * `settings`, group='zalo') — MySQL luôn dùng chung thật sự bất kể có bao nhiêu container, đây là
 * chốt chặn DUY NHẤT đảm bảo đúng trên mọi cấu hình hạ tầng (cùng cách đã sửa cho
 * MinihouseZaloTokenService trước khi hợp nhất — xem lịch sử git).
 */
class ZaloTokenService
{
    // Zalo trả về mã lỗi này khi access_token bị TỪ CHỐI ở tầng gửi ZNS (vd -124 "Access token
    // invalid") — có thể do Zalo thu hồi ngoài dự kiến dù cache/DB vẫn tưởng còn hạn (xem BUG THẬT
    // ở đầu file: 2 tiến trình giẫm chân nhau khi refresh). Nơi gọi (ZaloOtpService,
    // MinihouseZaloService, ZaloZnsService) dùng chung định nghĩa này để biết khi nào cần ép
    // refresh lại rồi thử gửi lại đúng 1 lần, thay vì fail hẳn rồi lặp lại y hệt cho tới khi Cache
    // tự hết hạn (tối đa 1 giờ, khiến MỌI request OTP trong giờ đó đều lỗi).
    public const INVALID_TOKEN_ERROR_CODES = [-124];

    public static function isInvalidTokenError(mixed $errorCode): bool
    {
        return in_array((int) $errorCode, self::INVALID_TOKEN_ERROR_CODES, true);
    }

    public function getAccessToken(bool $forceRefresh = false): string
    {
        if (! $forceRefresh) {
            $cached = Cache::get('zalo_access_token');
            if ($cached) {
                return $cached;
            }
        } else {
            // Token vừa bị Zalo từ chối — xoá cache để không ai khác đọc lại đúng token hỏng này
            // trong lúc ta refresh.
            Cache::forget('zalo_access_token');
        }

        return DB::transaction(function () use ($forceRefresh) {
            // Khoá THẬT trên bảng settings (group='zalo') — xem giải thích ở đầu file. Tiến trình
            // khác có thể đang giữ khoá này để refresh; ta CHỜ (transaction lock) tới khi họ xong
            // rồi mới đọc tiếp — không đọc song song với 1 refresh đang dở dang.
            DB::table('settings')->where('group', 'zalo')->lockForUpdate()->get();

            if (! $forceRefresh) {
                // Tiến trình vừa đợi khoá có thể đã có token mới do tiến trình giữ khoá
                // trước đó refresh xong — kiểm tra lại trước khi tự refresh thêm lần nữa.
                $cached = Cache::get('zalo_access_token');
                if ($cached) {
                    return $cached;
                }
            }

            $settings = $this->loadSettingsSafely();

            // Cache trống nhưng DB vẫn còn access_token chưa hết hạn (vd Redis vừa mất dữ liệu
            // ngay sau deploy) — dùng lại luôn, không cần gọi Zalo, đồng thời nạp lại vào Cache.
            // BỎ QUA nhánh này khi $forceRefresh=true: Zalo vừa báo token này bị từ chối, dù DB
            // ghi "chưa hết hạn" theo thời gian cũng không còn đáng tin — phải refresh mới thật sự.
            if (
                ! $forceRefresh
                && $settings
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

    // $settings PHẢI được đọc trong CÙNG transaction đã lockForUpdate() ở getAccessToken() —
    // refresh_token ưu tiên lấy từ ĐÓ (DB, đã khoá nên luôn mới nhất), KHÔNG còn ưu tiên đọc
    // Cache::get('zalo_refresh_token') như bản cũ (Cache có thể không dùng chung giữa các tiến
    // trình, xem BUG THẬT ở đầu file) — config('zalo.refresh_token') (.env) là lưới an toàn CHÓT
    // CÙNG, chỉ dùng khi DB cũng trống hẳn (VD lần đầu deploy, chưa refresh lần nào).
    private function refresh(?ZaloSettings $settings = null): string
    {
        $settings ??= $this->loadSettingsSafely();
        $refreshToken = $settings?->refresh_token ?: config('zalo.refresh_token');

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

        // Không còn ghi 'zalo_refresh_token' vào Cache nữa — refresh() giờ chỉ đọc DB (xem comment
        // ở refresh()), giữ Cache nguyên văn cũ chỉ tổ gây hiểu nhầm còn dùng.
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

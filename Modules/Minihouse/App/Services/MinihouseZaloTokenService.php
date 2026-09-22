<?php

namespace Modules\Minihouse\App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\ZaloSetting;

// Refresh access_token/refresh_token cho Zalo OA RIÊNG của MiniHouse — mirror đúng cơ chế
// App\Services\ZaloTokenService (khoá Cache::lock() khi refresh vì refresh_token của Zalo chỉ dùng
// được 1 lần, ghi lại vào DB làm lớp lưu bền vững phía sau Cache) nhưng đọc/ghi ZaloSetting riêng —
// KHÔNG đụng Cache key hay bảng settings của Home, tránh 2 Zalo OA khác nhau giẫm lên nhau.
class MinihouseZaloTokenService
{
    private const CACHE_ACCESS_TOKEN = 'minihouse_zalo_access_token';

    // BUG THẬT đã gặp (2026-09-22): trước đây refresh() ưu tiên đọc refresh_token từ Cache (TTL 3
    // tháng) thay vì DB — admin dán refresh_token MỚI qua ZaloSettingsPage chỉ update() DB, Cache vẫn
    // giữ token CŨ đã chết nên request kế tiếp vẫn gửi token chết lên Zalo, lỗi lặp lại y hệt dù đã
    // nhập token mới. Đã bỏ hẳn việc đọc refresh_token từ Cache (xem refresh() — giờ luôn lấy từ DB,
    // đã khoá lockForUpdate() nên luôn mới nhất) nên lỗi này KHÔNG còn tái diễn được nữa, không cần
    // admin nhớ "flush cache" thủ công. Vẫn giữ hàm này làm công cụ ép buộc refresh lại NGAY (VD sau
    // khi Zalo tự thu hồi token từ phía họ mà access_token cache cục bộ chưa kịp hết hạn).
    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_ACCESS_TOKEN);
    }

    // BUG THẬT thứ 2 đã gặp (2026-09-22, sau khi đã sửa bug flushCache ở trên): dán refresh_token mới
    // xong vẫn "Invalid refresh token." lặp lại sau vài giờ, KHÔNG do ai đụng vào cấu hình. Nguyên
    // nhân: Cache::lock() ở bản cũ CHỈ loại trừ lẫn nhau đúng nghĩa NẾU mọi tiến trình gọi hàm này
    // dùng CHUNG 1 backend cache (Redis...) — web app + cron/queue (VD lệnh
    // minihouse:send-reminder-notifications) rất có thể chạy ở CONTAINER/tiến trình KHÁC nhau, và
    // nếu CACHE_DRIVER là 'file'/'array' thì mỗi bên có bộ nhớ cache RIÊNG, lock không có tác dụng
    // xuyên container. Khi 2 tiến trình cùng lúc thấy access_token hết hạn và cùng đọc
    // ZaloSetting->refresh_token (cùng 1 giá trị, đọc TRƯỚC KHI bên nào ghi lại), bên gọi Zalo TRƯỚC
    // refresh thành công (Zalo lập tức thu hồi token đó), bên gọi SAU vẫn cầm đúng token đã bị thu
    // hồi → Zalo trả "Invalid refresh token." y hệt, dù refresh_token vừa dán vào vẫn còn "mới".
    //
    // Sửa TẬN GỐC bằng khoá THẬT ở tầng DB (`lockForUpdate()`, transaction) thay vì Cache::lock() —
    // MySQL luôn là 1 kết nối DÙNG CHUNG thật sự cho mọi container/tiến trình (khác Cache có thể
    // không dùng chung), nên đây là chốt chặn DUY NHẤT đảm bảo đúng bất kể hạ tầng cache thế nào.
    // Tiến trình đợi khoá xong sẽ tự đọc lại ĐÚNG bản ghi MỚI NHẤT (không phải bản đã tải trước đó),
    // không bao giờ còn gửi refresh_token đã bị thu hồi lên Zalo nữa.
    public function getAccessToken(): string
    {
        $cached = Cache::get(self::CACHE_ACCESS_TOKEN);

        if ($cached) {
            return $cached;
        }

        return DB::transaction(function () {
            // KHÔNG cứng where('id', 1) — xem comment sửa ở ZaloSetting::current() (bug cũ khiến
            // dòng thật có thể mang id khác 1 nếu từng bị xoá tay 1 lần); lấy dòng ĐẦU TIÊN, tạo mới
            // nếu bảng đang trống hẳn.
            $settings = ZaloSetting::query()->lockForUpdate()->first() ?? ZaloSetting::current();

            // Tiến trình khác có thể đã refresh xong TRONG LÚC ta chờ khoá — đọc lại Cache lần nữa
            // trước khi tự refresh thêm, tránh gọi Zalo thừa 1 lần cho access_token vẫn còn hạn.
            $cached = Cache::get(self::CACHE_ACCESS_TOKEN);

            if ($cached) {
                return $cached;
            }

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

    // $settings PHẢI được đọc trong CÙNG transaction đã lockForUpdate() ở getAccessToken() — refresh_
    // token lấy TRỰC TIẾP từ đó (KHÔNG còn ưu tiên đọc Cache::get(CACHE_REFRESH_TOKEN) như bản cũ),
    // vì Cache có thể không dùng chung giữa các tiến trình còn DB (đã khoá) thì luôn đúng và mới nhất.
    private function refresh(ZaloSetting $settings): string
    {
        $refreshToken = $settings->refresh_token;

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

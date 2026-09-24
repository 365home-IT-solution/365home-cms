<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CameraSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Đăng nhập Frigate bằng tài khoản/mật khẩu (Frigate xác thực qua phiên đăng nhập, KHÔNG có API
// key tĩnh như go2rtc trần) — cookie phiên lấy từ đây được cấp cho Node WebSocket proxy
// (websocket/server.js) qua route nội bộ POST /internal/frigate-session (xem routes/web.php), để
// Node tự thay mặt người dùng mở kết nối WebSocket sang Frigate, tránh việc trình duyệt phải tự
// đăng nhập Frigate (vốn không khả thi vì Frigate và 365home-cms khác domain — cookie không tự gửi
// kèm qua domain khác).
//
// MỖI ĐỐI TÁC 1 server Frigate riêng (App\Models\CameraSetting, thay cho App\Settings\CameraSettings
// dùng chung cũ) — class này giờ KHÔNG còn là service DI dùng chung 1 instance cho mọi partner, phải
// tạo qua forPartner() để gắn đúng cấu hình + cache session RIÊNG của partner đó (2 partner khác
// server Frigate mà dùng chung 1 cookie phiên sẽ đăng nhập nhầm/ghi đè cookie lẫn nhau).
class FrigateSessionClient
{
    private const CACHE_KEY_PREFIX = 'frigate_session_cookie:';

    // Phiên đăng nhập không có TTL rõ ràng phía Frigate — cache 6 tiếng rồi tự đăng nhập lại, ngắn
    // hơn nhiều so với thời gian phiên JWT thường sống, tránh trường hợp phiên hết hạn giữa chừng
    // mà không hay biết cho tới khi có người xem camera bị lỗi.
    private const CACHE_TTL_SECONDS = 6 * 3600;

    public function __construct(private readonly CameraSetting $settings)
    {
    }

    public static function forPartner(?string $partnerId): self
    {
        return new self(CameraSetting::forPartner($partnerId));
    }

    private function cacheKey(): string
    {
        return self::CACHE_KEY_PREFIX . ($this->settings->partner_id ?? 'none');
    }

    // Trả về chuỗi header "Cookie: ..." để gắn vào request lấy luồng video, hoặc null nếu chưa cấu
    // hình / đăng nhập thất bại (lỗi cụ thể ghi vào $error nếu được truyền vào).
    public function getSessionCookie(bool $forceRelogin = false, ?string &$error = null): ?string
    {
        if (! $this->settings->hasFrigateCredentials()) {
            $error = 'Chưa cấu hình tài khoản/mật khẩu Frigate (vào Cấu hình web > Camera).';

            return null;
        }

        if (! $forceRelogin) {
            $cached = Cache::get($this->cacheKey());

            if ($cached !== null) {
                return $cached;
            }
        }

        $baseUrl = rtrim((string) $this->settings->base_url, '/');

        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->timeout(10)
                ->post("{$baseUrl}/api/login", [
                    'user'     => $this->settings->username,
                    'password' => $this->settings->password,
                ]);
        } catch (\Throwable $e) {
            Log::warning('FrigateSessionClient: không kết nối được để đăng nhập', ['error' => $e->getMessage()]);
            $error = 'Không kết nối được tới server Frigate: ' . $e->getMessage();

            return null;
        }

        if ($response->failed()) {
            $error = "Đăng nhập Frigate thất bại (HTTP {$response->status()}) — kiểm tra lại tài khoản/mật khẩu.";

            return null;
        }

        // Đọc mảng header Set-Cookie THÔ qua PSR-7 (getHeader trả về 1 phần tử/dòng Set-Cookie gốc)
        // — KHÔNG dùng $response->header() (gộp nhiều header cùng tên bằng ", ", trong khi giá trị
        // Set-Cookie thường tự chứa dấu phẩy ở phần Expires="Wed, 21 Oct...", ghép nhầm sẽ làm hỏng
        // cookie).
        $setCookieHeaders = $response->toPsrResponse()->getHeader('Set-Cookie');

        if (empty($setCookieHeaders)) {
            $error = 'Frigate không trả về cookie phiên đăng nhập — có thể server này không dùng cơ chế đăng nhập như dự kiến.';

            return null;
        }

        // Mỗi phần tử là 1 cookie riêng (VD "frigate_token=xyz; Path=/; HttpOnly") — chỉ lấy phần
        // "tên=giá trị" đầu tiên, bỏ các thuộc tính (Path=, HttpOnly, SameSite=...), rồi nối lại
        // bằng "; " đúng định dạng header Cookie gửi đi ở các request sau.
        $cookiePairs = [];

        foreach ($setCookieHeaders as $part) {
            $pair = trim(explode(';', $part)[0] ?? '');

            if ($pair !== '' && str_contains($pair, '=')) {
                $cookiePairs[] = $pair;
            }
        }

        if (empty($cookiePairs)) {
            $error = 'Không đọc được cookie phiên từ phản hồi đăng nhập Frigate.';

            return null;
        }

        $cookie = implode('; ', $cookiePairs);

        Cache::put($this->cacheKey(), $cookie, self::CACHE_TTL_SECONDS);

        return $cookie;
    }

    public function forgetSession(): void
    {
        Cache::forget($this->cacheKey());
    }
}

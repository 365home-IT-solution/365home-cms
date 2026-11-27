<?php

namespace Modules\Motrak\App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Motrak\App\Exceptions\MotrakApiException;

/**
 * Tự đăng nhập Motrak để lấy Access Token mới khi Token hết hạn (không cần dán tay).
 *
 * API ĐÃ KIỂM CHỨNG: POST {motrak.login_endpoint} JSON {"username","password"} → {"data":{"accessToken","expiresIn"(giây),"refreshToken",...},"isError":false,...}.
 * Chỉ dùng accessToken + expiresIn. KHÔNG dùng refreshToken (endpoint làm mới chưa kiểm chứng) — hết hạn thì đăng nhập lại.
 *
 * An toàn: mật khẩu mã hoá khi lưu, chỉ đọc trong login(); KHÔNG bao giờ vào log / exception / response / nhật ký đồng bộ. Mọi thông báo lỗi đã được làm sạch.
 * Có khoá chống nhiều tiến trình cùng đăng nhập (đăng nhập dồn dập có thể bị Motrak giới hạn / khoá tài khoản).
 */
class MotrakAuthService
{
    public function __construct(private MotrakSettingsService $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->settings->hasCredentials();
    }

    /** Có tài khoản lưu sẵn: bảo đảm Token còn dùng được (còn hạn quá ngưỡng an toàn) — thiếu / sắp hết hạn thì đăng nhập lấy mới. Không có tài khoản: không làm gì. */
    public function ensureFreshToken(): void
    {
        if (! $this->enabled()) {
            return;
        }
        $margin = (int) config('motrak.auth.refresh_margin_seconds', 120);
        if ($this->settings->tokenUsableFor($margin)) {
            return;
        }

        $this->login(onlyIfStale: true);
    }

    /**
     * Đăng nhập và lưu Token mới. $onlyIfStale: sau khi có khoá, nếu tiến trình khác vừa lấy Token mới thì bỏ qua.
     *
     * @throws MotrakApiException AUTH_FAILED (sai tên/mật khẩu), NOT_CONFIGURED, NO_RESPONSE, CONNECTION, SERVER_ERROR, INVALID_RESPONSE, RATE_LIMITED
     */
    public function login(bool $onlyIfStale = false): void
    {
        $creds = $this->settings->credentials();
        $seconds = (int) config('motrak.auth.lock_seconds', 15);
        $lock = Cache::lock('motrak:login', $seconds);

        try {
            $lock->block($seconds);
        } catch (\Throwable) {
            throw new MotrakApiException(MotrakApiException::CONNECTION, 'Đang có tiến trình khác đăng nhập Motrak, thử lại sau ít giây.');
        }

        try {
            if ($onlyIfStale) {
                $this->settings->refresh();
                if ($this->settings->tokenUsableFor((int) config('motrak.auth.refresh_margin_seconds', 120))) {
                    return;
                }
            }

            $this->doLogin($creds['username'], $creds['password']);
        } finally {
            $lock->release();
        }
    }

    private function doLogin(string $username, string $password): void
    {
        $cfg = (array) config('motrak.http');

        try {
            $response = Http::acceptJson()->asJson()
                ->timeout((int) ($cfg['timeout'] ?? 15))->connectTimeout((int) ($cfg['connect_timeout'] ?? 5))
                ->post((string) config('motrak.login_endpoint'), ['username' => $username, 'password' => $password]);
        } catch (ConnectionException $e) {
            $m = strtolower($e->getMessage());
            throw str_contains($m, 'timed out') || str_contains($m, 'timeout') || str_contains($m, 'error 28')
                ? new MotrakApiException(MotrakApiException::NO_RESPONSE, 'Motrak không phản hồi kịp khi đăng nhập (quá thời gian chờ).')
                : new MotrakApiException(MotrakApiException::CONNECTION, 'Không kết nối được tới Motrak khi đăng nhập.');
        } catch (\Throwable) {
            // Không nối $e->getMessage(): có thể chứa dữ liệu đã gửi đi.
            throw new MotrakApiException(MotrakApiException::CONNECTION, 'Lỗi khi đăng nhập Motrak.');
        }

        $status = $response->status();
        $json = $response->json();

        if ($status === 429) {
            throw new MotrakApiException(MotrakApiException::RATE_LIMITED, 'Motrak giới hạn tốc độ đăng nhập (HTTP 429). Thử lại sau.', $status);
        }
        if ($status >= 500) {
            throw new MotrakApiException(MotrakApiException::SERVER_ERROR, "Máy chủ Motrak báo lỗi khi đăng nhập (HTTP {$status}).", $status);
        }
        if (in_array($status, [400, 401, 403], true) || (is_array($json) && ($json['isError'] ?? false) === true)) {
            throw new MotrakApiException(MotrakApiException::AUTH_FAILED, 'Motrak từ chối tên đăng nhập / mật khẩu. Kiểm tra lại tài khoản đã lưu.', $status ?: null);
        }

        $token = is_array($json) ? ($json['data']['accessToken'] ?? ($json['data']['json']['access_token'] ?? null)) : null;
        if (! is_string($token) || strlen($token) < 8 || preg_match('/\s/', $token)) {
            throw new MotrakApiException(MotrakApiException::INVALID_RESPONSE, 'Phản hồi đăng nhập của Motrak không có accessToken hợp lệ.', $status);
        }

        $default = (int) config('motrak.auth.default_ttl_seconds', 3600);
        $ttl = is_array($json) && is_numeric($json['data']['expiresIn'] ?? null) ? (int) $json['data']['expiresIn'] : $default;
        $this->settings->storeLoginToken($token, $ttl > 0 ? $ttl : $default);
    }
}

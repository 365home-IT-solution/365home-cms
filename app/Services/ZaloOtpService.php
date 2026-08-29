<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ZaloOtpService
{
    public function __construct(private ZaloTokenService $tokenService)
    {
    }

    public function hasCooldown(string $phone): bool
    {
        return Cache::has($this->cooldownKey($this->normalizePhone($phone)));
    }

    public function hasReachedDailyLimit(string $phone): bool
    {
        $key = $this->dailyKey($this->normalizePhone($phone));
        return (int) Cache::get($key, 0) >= config('zalo.otp_daily_limit');
    }

    public function send(string $phone): bool
    {
        $otp             = $this->generateOtp();
        $normalizedPhone = $this->normalizePhone($phone);

        // Tăng bộ đếm ngày trước khi gửi
        $dailyKey = $this->dailyKey($normalizedPhone);
        Cache::put($dailyKey, (int) Cache::get($dailyKey, 0) + 1, now()->endOfDay());

        Cache::put($this->otpKey($normalizedPhone), $otp, config('zalo.otp_ttl'));
        Cache::put($this->attemptKey($normalizedPhone), 0, config('zalo.otp_ttl'));
        Cache::put($this->cooldownKey($normalizedPhone), true, config('zalo.otp_cooldown'));

        return $this->sendViaZns($normalizedPhone, $otp);
    }

    public function verify(string $phone, string $otp): bool
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $attemptKey      = $this->attemptKey($normalizedPhone);
        $attempts        = (int) Cache::get($attemptKey, 0);

        if ($attempts >= config('zalo.otp_max_attempts')) {
            return false;
        }

        $stored = Cache::get($this->otpKey($normalizedPhone));

        if (! $stored || $stored !== $otp) {
            Cache::put($attemptKey, $attempts + 1, config('zalo.otp_ttl'));
            return false;
        }

        Cache::forget($this->otpKey($normalizedPhone));
        Cache::forget($attemptKey);

        return true;
    }

    public function normalizePhone(string $phone): string
    {
        // 0912345678 → 84912345678 (Zalo ZNS không chấp nhận dấu +)
        if (str_starts_with($phone, '0')) {
            return '84' . substr($phone, 1);
        }

        // Bỏ dấu + nếu có: +84... → 84...
        if (str_starts_with($phone, '+')) {
            return substr($phone, 1);
        }

        return $phone;
    }

    private function generateOtp(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function sendViaZns(string $phone, string $otp): bool
    {
        // Dev mode: log mà không gửi thật, KHÔNG log OTP plaintext
        if (config('app.env') === 'local') {
            Log::info('Zalo OTP [DEV MODE - NOT SENT]', ['phone' => $phone]);
            return true;
        }

        // Bypass mode: dùng khi test trên môi trường không phải local (vd: APP_ENV=production + domain .test)
        if (config('app.otp_bypass_enabled', false)) {
            Log::info('[OTP_BYPASS] Mã OTP để test: ' . $otp . ' - Phone: ' . $phone);
            return true;
        }

        // Thiếu config trong production → fail rõ ràng, không giả vờ thành công
        if (! config('zalo.otp_template_id')) {
            Log::critical('Zalo OTP: ZALO_OTP_TEMPLATE_ID chưa được cấu hình trong production!');
            return false;
        }

        try {
            $accessToken = $this->tokenService->getAccessToken();
        } catch (\Throwable $e) {
            Log::critical('Zalo OTP: không lấy được access token', ['message' => $e->getMessage()]);
            return false;
        }

        $response = Http::withHeaders([
            'access_token' => $accessToken,
            'Content-Type' => 'application/json',
        ])->post(config('zalo.zns_url'), [
            'phone'           => $phone,
            'template_id'     => config('zalo.otp_template_id'),
            'template_data'   => ['otp' => $otp],
            'tracking_id'     => 'otp_' . time(),
            'appsecret_proof' => hash_hmac('sha256', $accessToken, config('zalo.app_secret')),
        ]);

        if (! $response->successful() || ($response->json('error') !== 0)) {
            Log::error('Zalo ZNS failed', [
                'phone'    => $phone,
                'response' => $response->json(),
            ]);
            return false;
        }

        return true;
    }

    private function otpKey(string $phone): string
    {
        return 'zalo_otp:' . $phone;
    }

    private function attemptKey(string $phone): string
    {
        return 'zalo_otp_attempts:' . $phone;
    }

    private function cooldownKey(string $phone): string
    {
        return 'zalo_otp_cooldown:' . $phone;
    }

    private function dailyKey(string $phone): string
    {
        return 'zalo_otp_daily:' . now()->format('Y-m-d') . ':' . $phone;
    }

    // ── Phone token (dùng cho flow đăng ký) ──────────────────────────────

    public function storePhoneToken(string $normalizedPhone): string
    {
        $token = Str::random(64);
        Cache::put('pre_reg:' . $token, $normalizedPhone, now()->addMinutes(30));
        return $token;
    }

    public function getPhoneByToken(string $token): ?string
    {
        return Cache::get('pre_reg:' . $token);
    }

    public function consumePhoneToken(string $token): void
    {
        Cache::forget('pre_reg:' . $token);
    }
}

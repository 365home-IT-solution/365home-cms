<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZaloOtpService
{
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
        if (config('app.env') !== 'production' || ! config('zalo.otp_template_id')) {
            Log::info('Zalo OTP [DEV MODE]', ['phone' => $phone, 'otp' => $otp]);
            return true;
        }

        $response = Http::withHeaders([
            'access_token' => $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ])->post(config('zalo.zns_url'), [
            'phone'         => $phone,
            'template_id'   => config('zalo.otp_template_id'),
            'template_data' => ['otp' => $otp],
            'tracking_id'   => 'otp_' . time(),
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

    private function getAccessToken(): string
    {
        $cached = Cache::get('zalo_access_token');
        if ($cached) {
            return $cached;
        }

        $refreshToken = Cache::get('zalo_refresh_token') ?? config('zalo.refresh_token');

        $response = Http::asForm()
            ->withHeaders(['secret_key' => config('zalo.app_secret')])
            ->post(config('zalo.oauth_url'), [
                'app_id'        => config('zalo.app_id'),
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

        $data = $response->json();

        if (! isset($data['access_token'])) {
            Log::warning('Zalo token refresh failed, using static token', ['response' => $data]);
            return config('zalo.access_token');
        }

        $expiresIn = $data['expires_in'] ?? 3600;
        Cache::put('zalo_access_token', $data['access_token'], now()->addSeconds($expiresIn));
        if (isset($data['refresh_token'])) {
            Cache::put('zalo_refresh_token', $data['refresh_token'], now()->addMonths(3));
        }

        return $data['access_token'];
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
}

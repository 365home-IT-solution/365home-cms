<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\LockNotificationMail;
use App\Models\PartnerContractVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Mã OTP xác thực danh tính đối tác khi ký hợp đồng điện tử qua link công khai (không cần tài
// khoản CMS — xem ContractSignController). Cùng cơ chế cache-based như ZaloOtpService (OTP 6 số,
// TTL, giới hạn số lần thử, cooldown gửi lại) nhưng gửi qua EMAIL thay vì SMS/Zalo.
class ContractOtpService
{
    private const OTP_TTL = 300; // 5 phút

    private const MAX_ATTEMPTS = 5;

    private const COOLDOWN = 60; // 1 phút giữa 2 lần gửi

    public function hasCooldown(PartnerContractVersion $version): bool
    {
        return Cache::has($this->cooldownKey($version));
    }

    public function send(PartnerContractVersion $version, string $email): bool
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->otpKey($version), $otp, self::OTP_TTL);
        Cache::put($this->attemptKey($version), 0, self::OTP_TTL);
        Cache::put($this->cooldownKey($version), true, self::COOLDOWN);

        if (config('app.env') === 'local') {
            Log::info('Contract sign OTP [DEV MODE - NOT SENT]', ['version_id' => $version->id, 'otp' => $otp]);

            return true;
        }

        // Domain test/staging không có APP_ENV=local (vd Herd domain .test chạy production) nhưng
        // cũng chưa có mailer thật — dùng cùng cờ config đã có sẵn cho ZaloOtpService để bypass.
        if (config('app.otp_bypass_enabled', false)) {
            Log::info('[OTP_BYPASS] Mã OTP ký hợp đồng để test: ' . $otp, ['version_id' => $version->id]);

            return true;
        }

        try {
            Mail::to($email)->send(new LockNotificationMail(
                'Mã xác nhận ký hợp đồng điện tử',
                "<p>Mã xác nhận ký hợp đồng của bạn là:</p><h2 style=\"letter-spacing:4px;\">{$otp}</h2><p>Mã có hiệu lực trong 5 phút. Vui lòng không chia sẻ mã này cho bất kỳ ai.</p>"
            ));

            return true;
        } catch (\Throwable $e) {
            Log::error('Contract sign OTP mail failed', ['version_id' => $version->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function verify(PartnerContractVersion $version, string $otp): bool
    {
        $attemptKey = $this->attemptKey($version);
        $attempts   = (int) Cache::get($attemptKey, 0);

        if ($attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $stored = Cache::get($this->otpKey($version));

        if (! $stored || $stored !== $otp) {
            Cache::put($attemptKey, $attempts + 1, self::OTP_TTL);

            return false;
        }

        Cache::forget($this->otpKey($version));
        Cache::forget($attemptKey);

        return true;
    }

    private function otpKey(PartnerContractVersion $version): string
    {
        return 'contract_otp:' . $version->id;
    }

    private function attemptKey(PartnerContractVersion $version): string
    {
        return 'contract_otp_attempts:' . $version->id;
    }

    private function cooldownKey(PartnerContractVersion $version): string
    {
        return 'contract_otp_cooldown:' . $version->id;
    }
}

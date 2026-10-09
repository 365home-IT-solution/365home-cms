<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\LockNotificationMail;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// OTP xác nhận khi CHỦ ĐỐI TÁC tự đổi/bật/tắt kênh PayOS nhận tiền (đổi nơi nhận tiền là thao tác rủi ro: tài khoản bị chiếm có thể đổi
// sang tài khoản lạ). Gửi qua email của chính tài khoản đang thao tác; cùng cơ chế cache như ContractOtpService (6 số, 5 phút,
// tối đa 5 lần thử, cách 1 phút mới gửi lại). Super Admin nhập hộ không cần OTP.
class PartnerChannelOtpService
{
    private const OTP_TTL = 300;

    private const MAX_ATTEMPTS = 5;

    private const COOLDOWN = 60;

    public function hasCooldown(User $user, Partner $partner): bool
    {
        return Cache::has($this->key('cooldown', $user, $partner));
    }

    public function send(User $user, Partner $partner): bool
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->key('otp', $user, $partner), $otp, self::OTP_TTL);
        Cache::put($this->key('attempts', $user, $partner), 0, self::OTP_TTL);
        Cache::put($this->key('cooldown', $user, $partner), true, self::COOLDOWN);

        if (config('app.env') === 'local' || config('app.otp_bypass_enabled', false)) {
            Log::info('Mã OTP đổi kênh PayOS [DEV/BYPASS - KHÔNG GỬI]: ' . $otp, ['user_id' => $user->id, 'partner_id' => $partner->id]);

            return true;
        }

        try {
            Mail::to($user->email)->send(new LockNotificationMail(
                'Mã xác nhận đổi kênh PayOS nhận tiền',
                "<p>Mã xác nhận đổi kênh PayOS nhận tiền đặt phòng của " . e($partner->legal_name ?: $partner->name) . " là:</p><h2 style=\"letter-spacing:4px;\">{$otp}</h2><p>Mã có hiệu lực trong 5 phút. Nếu không phải bạn thực hiện, hãy bỏ qua email này và đổi mật khẩu ngay.</p>"
            ));

            return true;
        } catch (\Throwable $e) {
            Cache::forget($this->key('otp', $user, $partner));
            Cache::forget($this->key('attempts', $user, $partner));
            Cache::forget($this->key('cooldown', $user, $partner));
            Log::error('Gửi OTP đổi kênh PayOS thất bại', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function verify(User $user, Partner $partner, string $otp): bool
    {
        $attempts = (int) Cache::get($this->key('attempts', $user, $partner), 0);

        if ($attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $stored = Cache::get($this->key('otp', $user, $partner));

        if (! $stored || ! hash_equals((string) $stored, $otp)) {
            Cache::put($this->key('attempts', $user, $partner), $attempts + 1, self::OTP_TTL);

            return false;
        }

        Cache::forget($this->key('otp', $user, $partner));
        Cache::forget($this->key('attempts', $user, $partner));

        return true;
    }

    private function key(string $name, User $user, Partner $partner): string
    {
        return "payos_channel_otp:{$name}:{$partner->id}:{$user->id}";
    }
}

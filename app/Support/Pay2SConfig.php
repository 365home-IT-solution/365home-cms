<?php

namespace App\Support;

use App\Settings\Pay2SSettings;

/**
 * Cấu hình Pay2S: giá trị Super Admin nhập ở CMS (Cấu hình web → Pay2S — cấu hình chung) được ưu tiên; chưa nhập thì theo config/pay2s.php (PAY2S_*).
 * Đọc cài đặt lỗi (vd chưa chạy migration cài đặt khi vừa deploy) thì rơi về .env, không làm hỏng luồng thanh toán.
 */
class Pay2SConfig
{
    private const CMS_KEYS = ['enabled', 'api_token', 'partner_access_key', 'partner_secret_key', 'partner_api_url', 'partner_parent_origin', 'default_gateway'];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (in_array($key, self::CMS_KEYS, true)) {
            $value = self::fromCms($key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return config('pay2s.' . $key, $default);
    }

    public static function enabled(): bool
    {
        return (bool) self::get('enabled', true);
    }

    /** Cổng chuyển khoản mặc định của khoản thu MỚI: 'pay2s' hoặc 'payos' (giá trị lạ/trống = payos). */
    public static function gateway(): string
    {
        return strtolower((string) self::get('default_gateway', 'payos')) === 'pay2s' ? 'pay2s' : 'payos';
    }

    /** Khoản thu mới có đi qua Pay2S không: chọn cổng Pay2S + công tắc tổng Pay2S bật. */
    public static function collectsViaPay2S(): bool
    {
        return self::gateway() === 'pay2s' && self::enabled();
    }

    /**
     * Tài khoản ngân hàng Pay2S của 365home (không lưu DB: dựng từ cài đặt). Null nếu chưa khai báo đủ số tài khoản + ngân hàng.
     * $onlyWhenActive = false: bỏ điều kiện "đang chọn cổng Pay2S" — dùng để nhận diện tiền vào tài khoản này và tra yêu cầu đã phát hành.
     */
    public static function platformAccount(bool $onlyWhenActive = true): ?\Modules\Payment\Entities\PartnerSePayAccount
    {
        if ($onlyWhenActive && ! self::collectsViaPay2S()) {
            return null;
        }

        try {
            $s = app(Pay2SSettings::class);
            $bank = ['code' => (string) $s->platform_bank_code, 'bin' => (string) $s->platform_bank_bin, 'number' => trim((string) $s->platform_account_number),
                'holder' => (string) $s->platform_account_holder, 'token' => $s->platform_webhook_token];
        } catch (\Throwable) {
            // Cài đặt chưa có cột (vừa deploy, chưa migrate) hoặc không giải mã được: coi như chưa khai báo, không làm hỏng luồng thanh toán.
            return null;
        }

        if ($bank['number'] === '' || ($bank['bin'] === '' && $bank['code'] === '')) {
            return null;
        }

        $account = new \Modules\Payment\Entities\PartnerSePayAccount([
            'provider' => \Modules\Payment\Entities\PartnerSePayAccount::PROVIDER_PAY2S, 'is_active' => true,
            'bank_code' => $bank['code'], 'bank_bin' => $bank['bin'], 'account_number' => $bank['number'], 'account_holder' => $bank['holder'],
            'webhook_token' => $bank['token'],
        ]);
        $account->setAttribute('is_platform', true);

        return $account;
    }

    /** Nguồn của giá trị đang dùng: 'cms' | 'env' | 'none' — để CMS hiện rõ. */
    public static function source(string $key): string
    {
        $cms = self::fromCms($key);

        if ($cms !== null && $cms !== '') {
            return 'cms';
        }

        $env = config('pay2s.' . $key);

        return $env !== null && $env !== '' ? 'env' : 'none';
    }

    private static function fromCms(string $key): mixed
    {
        try {
            return app(Pay2SSettings::class)->{$key};
        } catch (\Throwable) {
            return null;
        }
    }
}

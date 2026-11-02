<?php

namespace App\Support;

use App\Settings\CashPaymentSettings;

/**
 * Cấu hình tiền mặt tại quầy: giá trị nhập ở CMS được ưu tiên; chưa nhập thì theo config/cash_payment.php (.env). Đọc cài đặt lỗi (vd chưa chạy
 * migration cài đặt khi vừa deploy) thì rơi về .env.
 */
class CashPaymentConfig
{
    public const KEYS = ['no_show_hours', 'guard_enabled', 'partner_day_limit', 'partner_month_limit', 'customer_day_limit', 'customer_month_limit'];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (in_array($key, self::KEYS, true) && ($value = self::fromCms($key)) !== null && $value !== '') {
            return $value;
        }

        return config('cash_payment.' . $key, $default);
    }

    /** 'cms' | 'env' | 'none' */
    public static function source(string $key): string
    {
        $cms = self::fromCms($key);

        if ($cms !== null && $cms !== '') {
            return 'cms';
        }

        $env = config('cash_payment.' . $key);

        return $env !== null && $env !== '' ? 'env' : 'none';
    }

    private static function fromCms(string $key): mixed
    {
        try {
            return app(CashPaymentSettings::class)->{$key};
        } catch (\Throwable) {
            return null;
        }
    }
}

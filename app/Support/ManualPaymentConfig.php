<?php

namespace App\Support;

use App\Settings\ManualPaymentSettings;

/**
 * Cấu hình "xác nhận thủ công": giá trị nhập ở CMS được ưu tiên; chưa nhập thì theo config/manual_payment.php (MANUAL_PAYMENT_*). Đọc cài đặt lỗi
 * (vd chưa chạy migration cài đặt khi vừa deploy) thì rơi về .env.
 */
class ManualPaymentConfig
{
    private const CMS_KEYS = ['hold_minutes', 'claimed_hold_minutes'];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (in_array($key, self::CMS_KEYS, true) && ($value = self::fromCms($key)) !== null && $value !== '') {
            return $value;
        }

        return config('manual_payment.' . $key, $default);
    }

    /** 'cms' | 'env' | 'none' */
    public static function source(string $key): string
    {
        $cms = self::fromCms($key);

        if ($cms !== null && $cms !== '') {
            return 'cms';
        }

        $env = config('manual_payment.' . $key);

        return $env !== null && $env !== '' ? 'env' : 'none';
    }

    private static function fromCms(string $key): mixed
    {
        try {
            return app(ManualPaymentSettings::class)->{$key};
        } catch (\Throwable) {
            return null;
        }
    }
}

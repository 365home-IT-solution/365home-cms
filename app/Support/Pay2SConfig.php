<?php

namespace App\Support;

use App\Settings\Pay2SSettings;

/**
 * Cấu hình Pay2S: giá trị Super Admin nhập ở CMS (Cấu hình web → Pay2S — cấu hình chung) được ưu tiên; chưa nhập thì theo config/pay2s.php (PAY2S_*).
 * Đọc cài đặt lỗi (vd chưa chạy migration cài đặt khi vừa deploy) thì rơi về .env, không làm hỏng luồng thanh toán.
 */
class Pay2SConfig
{
    private const CMS_KEYS = ['enabled', 'api_token'];

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

<?php

namespace App\Support;

use App\Settings\SePaySettings;

/**
 * Cấu hình SePay: giá trị Super Admin nhập ở CMS (Cấu hình web → SePay — cấu hình chung) được ưu tiên; chưa nhập (null / rỗng) thì theo config/sepay.php
 * (biến môi trường SEPAY_*). Đọc cài đặt lỗi (vd chưa chạy migration cài đặt khi vừa deploy) thì rơi về .env, không làm hỏng luồng thanh toán.
 */
class SePayConfig
{
    private const CMS_KEYS = ['enabled', 'webhook_secret', 'code_prefix', 'api_token', 'min_amount'];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (in_array($key, self::CMS_KEYS, true)) {
            $value = self::fromCms($key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return config('sepay.' . $key, $default);
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

        $env = config('sepay.' . $key);

        return $env !== null && $env !== '' ? 'env' : 'none';
    }

    private static function fromCms(string $key): mixed
    {
        try {
            return app(SePaySettings::class)->{$key};
        } catch (\Throwable) {
            return null;
        }
    }
}

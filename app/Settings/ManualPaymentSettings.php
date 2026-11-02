<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

// Cấu hình "xác nhận thủ công" chỉnh ở CMS (Cấu hình web → Xác nhận thủ công). null = chưa chỉnh → theo .env (MANUAL_PAYMENT_*). Đọc qua App\Support\ManualPaymentConfig.
class ManualPaymentSettings extends Settings
{
    public ?int $hold_minutes;

    public ?int $claimed_hold_minutes;

    public static function group(): string
    {
        return 'manual_payment';
    }
}

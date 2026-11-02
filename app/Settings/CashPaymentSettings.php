<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

// Cấu hình tiền mặt tại quầy chỉnh ở CMS (Cấu hình web → Tiền mặt tại quầy). null = chưa chỉnh → theo .env (config/cash_payment.php). Đọc qua App\Support\CashPaymentConfig.
class CashPaymentSettings extends Settings
{
    public ?int $no_show_hours;

    public ?bool $guard_enabled;

    public ?int $partner_day_limit;

    public ?int $partner_month_limit;

    public ?int $customer_day_limit;

    public ?int $customer_month_limit;

    public static function group(): string
    {
        return 'cash_payment';
    }
}

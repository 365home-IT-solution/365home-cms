<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

// Cấu hình CHUNG của Pay2S, chỉnh ở CMS (Cấu hình web → Pay2S — cấu hình chung), không cần sửa .env. null = chưa chỉnh ở CMS → theo biến môi trường PAY2S_*.
// Token webhook KHÔNG nằm ở đây: Pay2S sinh một token riêng cho từng webhook (từng tài khoản ngân hàng) nên token lưu theo tài khoản đối tác.
class Pay2SSettings extends Settings
{
    public ?bool $enabled;

    public ?string $api_token;

    // Partner API (BankHub — liên kết ngân hàng bằng Hosted Link).
    public ?string $partner_access_key;

    public ?string $partner_secret_key;

    public ?string $partner_api_url;

    public ?string $partner_parent_origin;

    // Cổng chuyển khoản mặc định: 'payos' | 'pay2s' (null = payos). Đặt 'pay2s' = mọi khoản thu mới đi qua Pay2S; link PayOS đã phát hành vẫn tra/nhận tiền được.
    public ?string $default_gateway;

    // Tài khoản ngân hàng (Pay2S) CỦA 365home — nhận các khoản 365 thu hộ. Token webhook của riêng tài khoản này (Pay2S sinh mỗi webhook một token).
    public ?string $platform_bank_code;

    public ?string $platform_bank_bin;

    public ?string $platform_account_number;

    public ?string $platform_account_holder;

    public ?string $platform_webhook_token;

    public static function group(): string
    {
        return 'pay2s';
    }

    public static function encrypted(): array
    {
        return ['api_token', 'partner_access_key', 'partner_secret_key', 'platform_webhook_token'];
    }
}

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

    public static function group(): string
    {
        return 'pay2s';
    }

    public static function encrypted(): array
    {
        return ['api_token', 'partner_access_key', 'partner_secret_key'];
    }
}

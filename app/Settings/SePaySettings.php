<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

// Cấu hình CHUNG của SePay, chỉnh ở CMS (Cấu hình web → SePay — cấu hình chung), không cần sửa .env. null = chưa chỉnh ở CMS → theo biến môi trường
// SEPAY_* của máy chủ; đã nhập ở CMS thì được ưu tiên hơn .env. Khoá bí mật được mã hoá khi lưu. Đọc qua App\Support\SePayConfig::get().
class SePaySettings extends Settings
{
    public ?bool $enabled;

    public ?string $webhook_secret;

    public ?string $code_prefix;

    public ?string $api_token;

    public ?int $min_amount;

    public static function group(): string
    {
        return 'sepay';
    }

    public static function encrypted(): array
    {
        return ['webhook_secret', 'api_token'];
    }
}

<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

// Cấu hình chữ ký số quản lý trong web (menu "Cấu hình web" > "Chữ ký số"). Giá trị để trống thì dùng lại .env / config/contract_signing.php.
class SigningSettings extends Settings
{
    public ?string $provider;          // local | vnpt_smartca (null = theo .env)

    public ?string $base_url;          // domain gốc VNPT SmartCA

    public ?string $client_id;

    public ?string $client_secret;

    public ?string $subscriber_user_id;     // CCCD chủ chứng thư

    public ?string $subscriber_password;    // chỉ cần đúng 1 lần đăng nhập đầu

    public static function group(): string
    {
        return 'signing';
    }

    public static function encrypted(): array
    {
        return ['client_id', 'client_secret', 'subscriber_user_id', 'subscriber_password'];
    }
}

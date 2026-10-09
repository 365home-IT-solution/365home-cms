<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

// Cấu hình chữ ký số RIÊNG của MiniHouse (trang "Hệ thống > Chữ ký số" ở panel MiniHouse) — TÁCH BIỆT HOÀN TOÀN với App\Settings\SigningSettings của
// Homestay: nhà cung cấp, tài khoản và công tắc ký số của 2 bên lưu ở 2 nơi khác nhau, bên này đổi không ảnh hưởng bên kia.
class MinihouseSigningSettings extends Settings
{
    public ?string $provider;          // local | vnpt_smartca | misa_esign | csc_custom (null = theo .env)

    public bool $pki_enabled;          // chủ trọ ký SỐ (PAdES) lên PDF cuối hợp đồng thuê (Mức C) — tắt = chỉ ký tay + OTP (Mức A)

    public ?string $base_url;

    public ?string $client_id;

    public ?string $client_secret;

    public ?string $subscriber_user_id;

    public ?string $subscriber_password;

    // Hồ sơ MISA eSign — xem CscRemoteSigningProvider.
    public ?string $misa_label;

    public ?string $misa_base_url;

    public ?string $misa_grant_type;

    public ?string $misa_client_id;

    public ?string $misa_client_secret;

    public ?string $misa_username;

    public ?string $misa_password;

    public ?string $misa_credential_id;

    public ?string $misa_pin;


    // Hồ sơ nhà cung cấp khác (chuẩn CSC) — xem CscRemoteSigningProvider.
    public ?string $csc_label;

    public ?string $csc_base_url;

    public ?string $csc_grant_type;

    public ?string $csc_client_id;

    public ?string $csc_client_secret;

    public ?string $csc_username;

    public ?string $csc_password;

    public ?string $csc_credential_id;

    public ?string $csc_pin;

    public static function group(): string
    {
        return 'minihouse_signing';
    }

    public static function encrypted(): array
    {
        return ['client_id', 'client_secret', 'subscriber_user_id', 'subscriber_password', 'misa_client_id', 'misa_client_secret', 'misa_username', 'misa_password', 'misa_credential_id', 'misa_pin', 'csc_client_id', 'csc_client_secret', 'csc_username', 'csc_password', 'csc_credential_id', 'csc_pin'];
    }
}

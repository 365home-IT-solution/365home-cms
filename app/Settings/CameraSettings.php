<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

// Cấu hình server go2rtc/Frigate — quản lý ngay trong web (menu "Cấu hình web" > "Camera") thay vì
// sửa file .env, để nhân viên vận hành không cần quyền truy cập server để đổi địa chỉ/tài khoản khi
// đổi server hoặc đổi mạng.
class CameraSettings extends Settings
{
    public ?string $base_url;

    // API key của go2rtc (CHỈ áp dụng nếu server chỉ là go2rtc trần có bật xác thực token — để
    // trống nếu dùng Frigate, Frigate không có khái niệm này, xem username/password bên dưới).
    public ?string $api_key;

    // Tài khoản đăng nhập Frigate (KHÔNG phải Frigate+) — Frigate xác thực bằng đăng nhập
    // tài khoản/mật khẩu (trả về cookie phiên), không phải API key tĩnh. Server 365home-cms tự
    // đăng nhập bằng tài khoản này để lấy luồng video thay người dùng — xem
    // App\Services\FrigateSessionClient.
    public ?string $username;

    public ?string $password;

    public static function group(): string
    {
        return 'camera';
    }

    public static function encrypted(): array
    {
        return ['api_key', 'username', 'password'];
    }

    public function isConfigured(): bool
    {
        return filled($this->base_url);
    }

    public function hasFrigateCredentials(): bool
    {
        return filled($this->username) && filled($this->password);
    }
}

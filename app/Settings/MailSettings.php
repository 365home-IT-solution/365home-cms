<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class MailSettings extends Settings
{
    public ?string $from_address;
    public ?string $from_name;
    public ?string $driver;
    public ?string $host;
    public int $port;
    public string $encryption;
    public ?string $username;
    public ?string $password;
    public ?int $timeout;
    public ?string $local_domain;
    public array $lock_notify_emails;

    public static function group(): string
    {
        return 'mail';
    }

    public static function encrypted(): array
    {
        return [
            'username',
            'password',
        ];
    }

    public function loadMailSettingsToConfig($data = null): void
    {
        config([
            'mail.mailers.smtp.host' => $data['host'] ?? $this->host,
            'mail.mailers.smtp.port' => $data['port'] ?? $this->port,
            'mail.mailers.smtp.encryption' => $data['encryption'] ?? $this->encryption,
            'mail.mailers.smtp.username' => $data['username'] ?? $this->username,
            'mail.mailers.smtp.password' => $data['password'] ?? $this->password,
            'mail.from.address' => $data['from_address'] ?? $this->from_address,
            'mail.from.name' => $data['from_name'] ?? $this->from_name,
        ]);
    }

    /**
     * Check if MailSettings is configured with necessary values.
     */
    // true khi hệ thống đã có cấu hình SMTP dùng được (Cài đặt Email trong admin). Không ném lỗi:
    // settings chưa khởi tạo hoặc username/password không giải mã được (APP_KEY khác) coi như chưa cấu hình.
    public static function canSend(): bool
    {
        try {
            return (bool) app(self::class)->isMailSettingsConfigured();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function isMailSettingsConfigured(): bool
    {
        // Check if the essential fields are not null
        return $this->host && $this->username && $this->password && $this->from_address;
    }
}

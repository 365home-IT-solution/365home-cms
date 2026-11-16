<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

// Cấu hình TURN / STUN cho gọi thoại mini game, chỉnh ở CMS (Mini game — Chat → Máy chủ TURN) hoặc /api/admin/games/voice-settings.
// null = chưa chỉnh ⇒ theo .env (config/services.php → turn). Đọc qua App\Support\VoiceConfig.
// turn_urls / stun_urls lưu dạng chuỗi, mỗi địa chỉ cách nhau dấu phẩy. turn_secret được mã hoá bằng APP_KEY.
class VoiceSettings extends Settings
{
    public ?string $turn_urls;

    public ?string $stun_urls;

    public ?string $turn_secret;

    public ?int $turn_ttl;

    public static function group(): string
    {
        return 'voice';
    }

    public static function encrypted(): array
    {
        return ['turn_secret'];
    }
}

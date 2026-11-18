<?php

declare(strict_types=1);

namespace Modules\Motrak\App\Settings;

use Spatie\LaravelSettings\Settings;

// Cấu hình GPS Motrak (CMS → GPS Tracking → Cấu hình Motrak). Cùng cơ chế với SePaySettings: lưu ở bảng settings, Token mã hoá khi lưu.
// Chỉ chứa cấu hình do người dùng nhập; TRẠNG THÁI kết nối suy ra từ bảng motrak_sync_logs (không ghi settings mỗi phút).
class MotrakSettings extends Settings
{
    public bool $enabled;

    public ?string $token;

    /** 4 ký tự cuối của Token — chỉ để người dùng nhận ra đang lưu token nào (không đủ để dùng). */
    public ?string $token_hint;

    public ?string $token_updated_at;

    public int $sync_interval_minutes;

    public static function group(): string
    {
        return 'motrak';
    }

    public static function encrypted(): array
    {
        return ['token'];
    }
}

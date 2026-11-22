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

    /** Tài khoản đăng nhập Motrak (tuỳ chọn): có thì hệ thống tự lấy Token mới khi hết hạn. */
    public ?string $username = null;

    /** Mật khẩu (mã hoá khi lưu, không bao giờ trả ra ngoài). */
    public ?string $password = null;

    /** Hết hạn của Token lấy qua đăng nhập (ISO 8601); null = Token nhập tay. */
    public ?string $token_expires_at = null;

    public static function group(): string
    {
        return 'motrak';
    }

    public static function encrypted(): array
    {
        return ['token', 'password'];
    }
}

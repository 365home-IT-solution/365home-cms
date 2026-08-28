<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    // Lưu access_token/refresh_token Zalo OA bền vững trong DB (bên cạnh Cache đang dùng làm nơi
    // đọc nhanh) — trước đây 2 giá trị này CHỈ sống trong Cache, mất là mất luôn (refresh_token của
    // Zalo dùng 1 lần nên .env không tự phục hồi được), khiến OTP gửi lỗi liên tục cho tới khi có
    // người vào lại Zalo OA lấy token mới dán tay vào .env. Xem ZaloTokenService.
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'zalo')->where('name', 'access_token')->exists()) {
            return;
        }

        $this->migrator->addEncrypted('zalo.access_token', null);
        $this->migrator->addEncrypted('zalo.refresh_token', null);
        $this->migrator->add('zalo.access_token_expires_at', null);
    }
};

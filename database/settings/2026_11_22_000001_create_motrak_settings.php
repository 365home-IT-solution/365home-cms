<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Cấu hình GPS Motrak ở CMS (GPS Tracking → Cấu hình Motrak). Token mã hoá khi lưu, không bao giờ trả ra ngoài. Mặc định: chưa cấu hình, tắt.
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'motrak')->where('name', 'enabled')->exists()) {
            return;
        }

        $this->migrator->add('motrak.enabled', false);
        $this->migrator->addEncrypted('motrak.token', null);
        $this->migrator->add('motrak.token_hint', null);
        $this->migrator->add('motrak.token_updated_at', null);
        $this->migrator->add('motrak.sync_interval_minutes', 1);
    }
};

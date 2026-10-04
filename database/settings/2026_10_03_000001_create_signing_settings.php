<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Cấu hình chữ ký số nhập ngay trong web (Cấu hình web → Chữ ký số) thay vì sửa .env.
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'signing')->where('name', 'provider')->exists()) {
            return;
        }

        $this->migrator->add('signing.provider', null);
        $this->migrator->add('signing.base_url', null);
        $this->migrator->addEncrypted('signing.client_id', null);
        $this->migrator->addEncrypted('signing.client_secret', null);
        $this->migrator->addEncrypted('signing.subscriber_user_id', null);
        $this->migrator->addEncrypted('signing.subscriber_password', null);
    }
};

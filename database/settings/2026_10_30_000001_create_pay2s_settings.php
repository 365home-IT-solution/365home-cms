<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Cấu hình chung của Pay2S ở CMS: mặc định null hết = theo .env (deploy không đổi hành vi nào cho tới khi Super Admin nhập ở CMS).
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'pay2s')->where('name', 'enabled')->exists()) {
            return;
        }

        $this->migrator->add('pay2s.enabled', null);
        $this->migrator->addEncrypted('pay2s.api_token', null);
    }
};

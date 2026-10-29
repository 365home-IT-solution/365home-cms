<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Cấu hình chung của SePay ở CMS: mặc định null hết = theo .env (deploy không đổi hành vi nào cho tới khi Super Admin nhập ở CMS).
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'sepay')->where('name', 'enabled')->exists()) {
            return;
        }

        $this->migrator->add('sepay.enabled', null);
        $this->migrator->addEncrypted('sepay.webhook_secret', null);
        $this->migrator->add('sepay.code_prefix', null);
        $this->migrator->addEncrypted('sepay.api_token', null);
        $this->migrator->add('sepay.min_amount', null);
    }
};

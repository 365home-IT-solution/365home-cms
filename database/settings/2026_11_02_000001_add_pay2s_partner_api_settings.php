<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Khoá Partner API (BankHub — liên kết ngân hàng bằng Hosted Link) của Pay2S nhập ở CMS; null = theo .env (PAY2S_PARTNER_*).
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'pay2s')->where('name', 'partner_access_key')->exists()) {
            return;
        }

        $this->migrator->addEncrypted('pay2s.partner_access_key', null);
        $this->migrator->addEncrypted('pay2s.partner_secret_key', null);
        $this->migrator->add('pay2s.partner_api_url', null);
        $this->migrator->add('pay2s.partner_parent_origin', null);
    }
};

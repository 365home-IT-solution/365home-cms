<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Cấu hình TURN / STUN gọi thoại mini game chỉnh ở CMS. null = theo .env (TURN_URLS, STUN_URLS, TURN_SECRET, TURN_TTL).
return new class extends SettingsMigration
{
    public function up(): void
    {
        foreach (['turn_urls', 'stun_urls', 'turn_ttl'] as $name) {
            if (! DB::table('settings')->where('group', 'voice')->where('name', $name)->exists()) {
                $this->migrator->add('voice.' . $name, null);
            }
        }

        if (! DB::table('settings')->where('group', 'voice')->where('name', 'turn_secret')->exists()) {
            $this->migrator->addEncrypted('voice.turn_secret', null);
        }
    }
};

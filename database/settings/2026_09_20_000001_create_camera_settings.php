<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'camera')->where('name', 'base_url')->exists()) {
            return;
        }

        $this->migrator->add('camera.base_url', null);
        $this->migrator->addEncrypted('camera.api_key', null);
    }
};

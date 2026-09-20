<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'camera')->where('name', 'username')->exists()) {
            return;
        }

        $this->migrator->addEncrypted('camera.username', null);
        $this->migrator->addEncrypted('camera.password', null);
    }
};

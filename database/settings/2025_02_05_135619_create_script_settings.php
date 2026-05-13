<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'general')->where('name', 'header_scripts')->exists()) {
            return;
        }

        $this->migrator->add('general.header_scripts', '');
        $this->migrator->add('general.body_scripts_top', '');
        $this->migrator->add('general.body_scripts_bottom', '');
        $this->migrator->add('general.footer_scripts', '');
    }

    public function down(): void {
        $this->migrator->delete('general.header_scripts');
        $this->migrator->delete('general.body_scripts_top');
        $this->migrator->delete('general.body_scripts_bottom');
        $this->migrator->delete('general.footer_scripts');
    }
};

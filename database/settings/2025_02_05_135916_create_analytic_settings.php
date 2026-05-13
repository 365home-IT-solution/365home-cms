<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'general')->where('name', 'google_analytics_id')->exists()) {
            return;
        }

        $this->migrator->add('general.google_analytics_id', null);
        $this->migrator->add('general.google_analytics_enabled', false);
        $this->migrator->add('general.google_tag_manager_id', null);
        $this->migrator->add('general.google_tag_manager_enabled', false);
        $this->migrator->add('general.google_site_verification', null);
    }

    public function down(): void {
        $this->migrator->delete('general.google_analytics_id');
        $this->migrator->delete('general.google_analytics_enabled');
        $this->migrator->delete('general.google_tag_manager_id');
        $this->migrator->delete('general.google_tag_manager_enabled');
        $this->migrator->delete('general.google_site_verification');
    }
};

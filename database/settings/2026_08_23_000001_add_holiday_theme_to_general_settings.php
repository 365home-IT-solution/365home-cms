<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.holiday_theme_active')) {
            $this->migrator->add('general.holiday_theme_active', false);
        }

        if (! $this->migrator->exists('general.holiday_logo_image')) {
            $this->migrator->add('general.holiday_logo_image', null);
        }
    }
};

<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.auth_header')) {
            $this->migrator->add('general.auth_header', ['enabled' => false]);
        }
    }
};

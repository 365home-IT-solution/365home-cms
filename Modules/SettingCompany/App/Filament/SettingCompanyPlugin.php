<?php

namespace Modules\SettingCompany\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class SettingCompanyPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'SettingCompany';
    }

    public function getId(): string
    {
        return 'settingcompany';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}

<?php

namespace Modules\SettingCompany\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class BranchPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Branch';
    }

    public function getId(): string
    {
        return 'branch';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}
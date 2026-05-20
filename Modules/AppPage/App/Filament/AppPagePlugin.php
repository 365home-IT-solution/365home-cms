<?php

namespace Modules\AppPage\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class AppPagePlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'AppPage';
    }

    public function getId(): string
    {
        return 'apppage';
    }

    public function boot(Panel $panel): void
    {
        //
    }
}

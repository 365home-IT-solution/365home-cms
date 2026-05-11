<?php

namespace Modules\AccessCode\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class AccessCodePlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'AccessCode';
    }

    public function getId(): string
    {
        return 'accesscode';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}

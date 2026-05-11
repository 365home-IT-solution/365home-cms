<?php

namespace Modules\Zns\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class ZnsPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Zns';
    }

    public function getId(): string
    {
        return 'zns';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}

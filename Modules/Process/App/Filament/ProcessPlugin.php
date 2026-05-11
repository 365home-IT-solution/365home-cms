<?php

namespace Modules\Process\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class ProcessPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Process';
    }

    public function getId(): string
    {
        return 'process';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}

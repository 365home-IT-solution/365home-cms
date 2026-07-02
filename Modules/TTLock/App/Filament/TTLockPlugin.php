<?php

namespace Modules\TTLock\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class TTLockPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'TTLock';
    }

    public function getId(): string
    {
        return 'ttlock';
    }

    public function boot(Panel $panel): void
    {
        //
    }
}

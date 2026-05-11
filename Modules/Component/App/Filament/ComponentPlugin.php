<?php

declare(strict_types=1);

namespace Modules\Component\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class ComponentPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Component';
    }

    public function getId(): string
    {
        return 'component';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}

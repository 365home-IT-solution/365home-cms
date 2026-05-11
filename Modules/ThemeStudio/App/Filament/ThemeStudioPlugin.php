<?php

declare(strict_types=1);

namespace Modules\ThemeStudio\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class ThemeStudioPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Themestudio';
    }

    public function getId(): string
    {
        return 'themestudio';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}
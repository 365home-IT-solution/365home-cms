<?php

namespace Modules\Promotion\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class PromotionPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Promotion';
    }

    public function getId(): string
    {
        return 'promotion';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}

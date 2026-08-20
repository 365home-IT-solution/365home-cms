<?php

namespace Modules\Warehouse\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class WarehousePlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Warehouse';
    }

    public function getId(): string
    {
        return 'warehouse';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}

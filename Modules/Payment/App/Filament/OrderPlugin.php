<?php

namespace Modules\Payment\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class OrderPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Payment';
    }

    public function getId(): string
    {
        return 'order';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}
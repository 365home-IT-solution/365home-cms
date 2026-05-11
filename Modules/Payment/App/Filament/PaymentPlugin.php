<?php

namespace Modules\Payment\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class PaymentPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Payment';
    }

    public function getId(): string
    {
        return 'payment';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}

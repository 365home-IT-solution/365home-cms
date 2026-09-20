<?php

declare(strict_types=1);

namespace Modules\Invoice\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class InvoicePlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Invoice';
    }

    public function getId(): string
    {
        return 'invoice';
    }

    public function boot(Panel $panel): void
    {
        //
    }
}

<?php

declare(strict_types=1);

namespace Modules\VehicleRental\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

// Gắn vào panel /homestay/admin (App\Providers\Filament\AdminPanelProvider) — tự phát hiện Resource trong module.
class VehicleRentalPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'VehicleRental';
    }

    public function getId(): string
    {
        return 'vehicle-rental';
    }

    public function boot(Panel $panel): void
    {
        //
    }
}

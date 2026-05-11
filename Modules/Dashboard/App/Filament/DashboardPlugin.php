<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Modules\Dashboard\App\Filament\Widgets\ATotalWidgets;
use Modules\Dashboard\App\Filament\Widgets\FormStatsWidget;
use Modules\Dashboard\App\Filament\Widgets\OrderStatsWidget;
use Modules\Dashboard\App\Filament\Widgets\PostStatsWidget;

class DashboardPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Dashboard';
    }

    public function getId(): string
    {
        return 'dashboard';
    }

    public function register(Panel $panel): void
    {
        $panel->widgets([
            ATotalWidgets::class,
            OrderStatsWidget::class,
            FormStatsWidget::class,
            PostStatsWidget::class,
        ]);
    }


    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}

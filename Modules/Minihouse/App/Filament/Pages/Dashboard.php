<?php

namespace Modules\Minihouse\App\Filament\Pages;

use Filament\Pages\Dashboard as FilamentDashboard;
use Modules\Minihouse\App\Filament\Widgets\MinihouseStatsWidget;
use Modules\Minihouse\App\Filament\Widgets\UpcomingRemindersWidget;

class Dashboard extends FilamentDashboard
{
    protected static ?string $navigationLabel = 'Tổng quan';

    protected static ?int $navigationSort = 0;

    public function getWidgets(): array
    {
        return [
            MinihouseStatsWidget::class,
            UpcomingRemindersWidget::class,
        ];
    }
}

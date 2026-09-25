<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\PortalBroadcastResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Modules\Minihouse\App\Filament\Resources\PortalBroadcastResource;

class ViewPortalBroadcast extends ViewRecord
{
    protected static string $resource = PortalBroadcastResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

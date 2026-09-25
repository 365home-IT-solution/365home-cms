<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\PortalBroadcastResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Minihouse\App\Filament\Resources\PortalBroadcastResource;

class ListPortalBroadcasts extends ListRecords
{
    protected static string $resource = PortalBroadcastResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Soạn thông báo'),
        ];
    }
}

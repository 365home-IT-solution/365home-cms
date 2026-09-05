<?php

namespace Modules\Minihouse\App\Filament\Resources\BuildingResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Minihouse\App\Filament\Resources\BuildingResource;

class ListBuildings extends ListRecords
{
    protected static string $resource = BuildingResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

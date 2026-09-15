<?php

namespace Modules\Metering\App\Filament\Resources\MeteringReadingResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Metering\App\Filament\Resources\MeteringReadingResource;

class ListMeteringReadings extends ListRecords
{
    protected static string $resource = MeteringReadingResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}

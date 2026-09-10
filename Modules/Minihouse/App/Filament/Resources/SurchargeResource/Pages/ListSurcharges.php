<?php

namespace Modules\Minihouse\App\Filament\Resources\SurchargeResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Minihouse\App\Filament\Resources\SurchargeResource;

class ListSurcharges extends ListRecords
{
    protected static string $resource = SurchargeResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}

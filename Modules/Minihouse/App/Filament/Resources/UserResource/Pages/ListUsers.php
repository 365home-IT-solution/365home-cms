<?php

namespace Modules\Minihouse\App\Filament\Resources\UserResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Minihouse\App\Filament\Resources\UserResource;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}

<?php

namespace Modules\Minihouse\App\Filament\Resources\PanoramaSceneResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Minihouse\App\Filament\Resources\PanoramaSceneResource;

class ListPanoramaScenes extends ListRecords
{
    protected static string $resource = PanoramaSceneResource::class;

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

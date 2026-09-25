<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\CameraResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Minihouse\App\Filament\Resources\CameraResource;

class ListCameras extends ListRecords
{
    protected static string $resource = CameraResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Thêm camera'),
        ];
    }
}

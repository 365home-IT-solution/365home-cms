<?php

namespace Modules\Minihouse\App\Filament\Resources\AmenityResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\AmenityResource;

class EditAmenity extends EditRecord
{
    protected static string $resource = AmenityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

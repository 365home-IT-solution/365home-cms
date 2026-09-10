<?php

namespace Modules\Minihouse\App\Filament\Resources\ZoneResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\ZoneResource;

class EditZone extends EditRecord
{
    protected static string $resource = ZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

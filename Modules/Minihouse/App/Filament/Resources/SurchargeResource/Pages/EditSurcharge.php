<?php

namespace Modules\Minihouse\App\Filament\Resources\SurchargeResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\SurchargeResource;

class EditSurcharge extends EditRecord
{
    protected static string $resource = SurchargeResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}

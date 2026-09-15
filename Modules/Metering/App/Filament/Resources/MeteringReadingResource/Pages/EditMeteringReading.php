<?php

namespace Modules\Metering\App\Filament\Resources\MeteringReadingResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\Metering\App\Filament\Resources\MeteringReadingResource;

class EditMeteringReading extends EditRecord
{
    protected static string $resource = MeteringReadingResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}

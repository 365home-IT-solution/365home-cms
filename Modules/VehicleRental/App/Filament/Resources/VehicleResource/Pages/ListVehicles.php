<?php

declare(strict_types=1);

namespace Modules\VehicleRental\App\Filament\Resources\VehicleResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\VehicleRental\App\Filament\Resources\VehicleResource;

class ListVehicles extends ListRecords
{
    protected static string $resource = VehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Thêm xe')];
    }
}

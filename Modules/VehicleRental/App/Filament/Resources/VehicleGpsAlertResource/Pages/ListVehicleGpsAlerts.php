<?php

declare(strict_types=1);

namespace Modules\VehicleRental\App\Filament\Resources\VehicleGpsAlertResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\VehicleRental\App\Filament\Resources\VehicleGpsAlertResource;

class ListVehicleGpsAlerts extends ListRecords
{
    protected static string $resource = VehicleGpsAlertResource::class;
}

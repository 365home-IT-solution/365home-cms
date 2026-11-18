<?php

declare(strict_types=1);

namespace Modules\VehicleRental\App\Filament\Resources\VehicleBookingResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\VehicleRental\App\Filament\Resources\VehicleBookingResource;

class ListVehicleBookings extends ListRecords
{
    protected static string $resource = VehicleBookingResource::class;
}

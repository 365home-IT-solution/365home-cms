<?php

declare(strict_types=1);

namespace Modules\VehicleRental\App\Filament\Resources\VehicleDocumentResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\VehicleRental\App\Filament\Resources\VehicleDocumentResource;

class ListVehicleDocuments extends ListRecords
{
    protected static string $resource = VehicleDocumentResource::class;
}

<?php

declare(strict_types=1);

namespace Modules\Motrak\App\Filament\Resources\MotrakVehicleResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Motrak\App\Filament\Resources\MotrakVehicleResource;

class ListMotrakVehicles extends ListRecords
{
    protected static string $resource = MotrakVehicleResource::class;
}

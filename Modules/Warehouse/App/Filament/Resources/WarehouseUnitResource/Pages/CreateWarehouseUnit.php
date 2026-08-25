<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseUnitResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Warehouse\App\Filament\Resources\WarehouseUnitResource;

class CreateWarehouseUnit extends CreateRecord
{
    protected static string $resource = WarehouseUnitResource::class;
}

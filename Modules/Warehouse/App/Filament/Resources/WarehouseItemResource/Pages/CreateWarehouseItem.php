<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseItemResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Warehouse\App\Filament\Resources\WarehouseItemResource;

class CreateWarehouseItem extends CreateRecord
{
    protected static string $resource = WarehouseItemResource::class;
}

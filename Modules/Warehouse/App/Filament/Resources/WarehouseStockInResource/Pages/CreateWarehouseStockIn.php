<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockInResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockInResource;

class CreateWarehouseStockIn extends CreateRecord
{
    protected static string $resource = WarehouseStockInResource::class;
}

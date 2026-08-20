<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseCategoryResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Warehouse\App\Filament\Resources\WarehouseCategoryResource;

class CreateWarehouseCategory extends CreateRecord
{
    protected static string $resource = WarehouseCategoryResource::class;
}

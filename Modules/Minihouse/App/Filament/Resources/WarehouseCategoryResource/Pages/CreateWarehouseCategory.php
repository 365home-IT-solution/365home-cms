<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\WarehouseCategoryResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Minihouse\App\Filament\Resources\WarehouseCategoryResource;

class CreateWarehouseCategory extends CreateRecord
{
    protected static string $resource = WarehouseCategoryResource::class;
}

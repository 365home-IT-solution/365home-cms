<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\WarehouseStockOutResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Minihouse\App\Filament\Resources\WarehouseStockOutResource;

class CreateWarehouseStockOut extends CreateRecord
{

    protected static string $resource = WarehouseStockOutResource::class;
}

<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource\Concerns\HasRoomBranchPicker;

class CreateWarehouseStockOut extends CreateRecord
{
    use HasRoomBranchPicker;

    protected static string $resource = WarehouseStockOutResource::class;
}

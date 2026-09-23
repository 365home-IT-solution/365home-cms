<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockReturnResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockReturnResource;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockReturnResource\Concerns\HasRoomBranchPicker;

class CreateWarehouseStockReturn extends CreateRecord
{
    use HasRoomBranchPicker;

    protected static string $resource = WarehouseStockReturnResource::class;
}

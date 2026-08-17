<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource;

class ListWarehouseStockOut extends ListRecords
{
    protected static string $resource = WarehouseStockOutResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

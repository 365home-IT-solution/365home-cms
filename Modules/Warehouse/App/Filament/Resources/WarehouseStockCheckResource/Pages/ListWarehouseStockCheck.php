<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockCheckResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockCheckResource;

class ListWarehouseStockCheck extends ListRecords
{
    protected static string $resource = WarehouseStockCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

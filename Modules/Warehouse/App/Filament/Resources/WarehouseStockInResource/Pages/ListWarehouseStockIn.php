<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockInResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockInResource;

class ListWarehouseStockIn extends ListRecords
{
    protected static string $resource = WarehouseStockInResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

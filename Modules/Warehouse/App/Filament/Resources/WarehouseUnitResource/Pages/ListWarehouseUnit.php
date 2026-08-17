<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseUnitResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Warehouse\App\Filament\Resources\WarehouseUnitResource;

class ListWarehouseUnit extends ListRecords
{
    protected static string $resource = WarehouseUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseCategoryResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Warehouse\App\Filament\Resources\WarehouseCategoryResource;

class ListWarehouseCategory extends ListRecords
{
    protected static string $resource = WarehouseCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

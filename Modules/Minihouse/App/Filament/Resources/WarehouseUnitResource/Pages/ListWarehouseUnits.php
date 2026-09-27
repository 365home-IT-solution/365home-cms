<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\WarehouseUnitResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Minihouse\App\Filament\Resources\WarehouseUnitResource;

class ListWarehouseUnits extends ListRecords
{
    protected static string $resource = WarehouseUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Thêm đơn vị tính')];
    }
}

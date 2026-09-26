<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\WarehouseCategoryResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Minihouse\App\Filament\Resources\WarehouseCategoryResource;

class ListWarehouseCategories extends ListRecords
{
    protected static string $resource = WarehouseCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Thêm nhóm vật tư')];
    }
}

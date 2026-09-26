<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\WarehouseCategoryResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\WarehouseCategoryResource;

class EditWarehouseCategory extends EditRecord
{
    protected static string $resource = WarehouseCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseItemResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Warehouse\App\Filament\Resources\WarehouseItemResource;

class EditWarehouseItem extends EditRecord
{
    protected static string $resource = WarehouseItemResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}

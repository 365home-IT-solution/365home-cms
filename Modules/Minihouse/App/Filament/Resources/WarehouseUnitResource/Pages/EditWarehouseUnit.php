<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\WarehouseUnitResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\WarehouseUnitResource;

class EditWarehouseUnit extends EditRecord
{
    protected static string $resource = WarehouseUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}

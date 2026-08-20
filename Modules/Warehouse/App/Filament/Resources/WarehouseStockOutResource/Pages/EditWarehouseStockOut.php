<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource\Concerns\HasRoomBranchPicker;
use Modules\Warehouse\App\Filament\Support\WarehousePrinter;
use Modules\Warehouse\App\Models\WarehouseStockOut;

class EditWarehouseStockOut extends EditRecord
{
    use HasRoomBranchPicker;

    protected static string $resource = WarehouseStockOutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('In phiếu')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->action(fn (WarehouseStockOut $record) => WarehousePrinter::stockOut($record)),
            DeleteAction::make(),
        ];
    }
}

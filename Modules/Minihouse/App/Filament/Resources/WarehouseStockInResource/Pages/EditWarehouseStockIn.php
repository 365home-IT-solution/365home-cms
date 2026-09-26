<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\WarehouseStockInResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\WarehouseStockInResource;
use Modules\Minihouse\App\Filament\Support\WarehousePrinter;
use Modules\Minihouse\App\Models\WarehouseStockIn;

class EditWarehouseStockIn extends EditRecord
{
    protected static string $resource = WarehouseStockInResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('In phiếu')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->action(fn (WarehouseStockIn $record) => WarehousePrinter::stockIn($record)),
            DeleteAction::make(),
        ];
    }
}

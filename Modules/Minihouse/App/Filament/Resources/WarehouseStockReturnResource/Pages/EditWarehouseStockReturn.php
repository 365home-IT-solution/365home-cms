<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\WarehouseStockReturnResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\WarehouseStockReturnResource;
use Modules\Minihouse\App\Filament\Support\WarehousePrinter;
use Modules\Minihouse\App\Models\WarehouseStockReturn;

class EditWarehouseStockReturn extends EditRecord
{

    protected static string $resource = WarehouseStockReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('In phiếu')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->action(fn (WarehouseStockReturn $record) => WarehousePrinter::stockReturn($record)),
            DeleteAction::make(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource\Concerns\HasRoomBranchPicker;
use Modules\Warehouse\App\Filament\Support\WarehousePrinter;
use Modules\Warehouse\App\Filament\Support\WarehouseStockOutReturnAction;
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
            // Xem giải thích đầy đủ ở WarehouseStockOutReturnAction — cùng 1 popup với row action ở
            // danh sách, chỉ khác chỗ gắn (header action ở trang sửa).
            Action::make('return_to_stock')
                ->label('Hoàn trả')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn () => auth()->user()?->can('create_warehouse::stock::return') ?? false)
                ->modalHeading(fn (WarehouseStockOut $record) => "Hoàn trả kho — Phiếu {$record->code}")
                ->modalSubmitActionLabel('Hoàn trả')
                ->modalWidth('2xl')
                ->form(fn (WarehouseStockOut $record) => WarehouseStockOutReturnAction::formSchema($record))
                ->action(fn (array $data, WarehouseStockOut $record) => WarehouseStockOutReturnAction::handle($data, $record)),
            DeleteAction::make(),
        ];
    }
}

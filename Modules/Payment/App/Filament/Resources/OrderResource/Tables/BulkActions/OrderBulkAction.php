<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Tables\BulkActions;

use Filament\Tables;
use Filament\Tables\Actions\BulkAction;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Payment\App\Exports\OrdersExport;

class OrderBulkAction
{
    public static function bulkActions(): array
    {
        return [
            BulkAction::make('export_excel')
                ->label('Xuất Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(function ($records) {
                    $orderIds = $records->pluck('id')->toArray();
                    return Excel::download(
                        new OrdersExport([], $orderIds),
                        'don-hang-da-chon-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make()
            ]),
        ];
    }
}
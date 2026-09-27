<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\WarehouseStockCheckResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Minihouse\App\Exports\WarehouseStockCheckExport;
use Modules\Minihouse\App\Filament\Resources\WarehouseStockCheckResource;

class ListWarehouseStockChecks extends ListRecords
{
    protected static string $resource = WarehouseStockCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Xuất Excel danh sách phiếu kiểm kê ĐANG hiển thị trên bảng (bám theo bộ lọc/tìm kiếm
            // hiện tại, không phải toàn bộ).
            Action::make('exportStockChecksExcel')
                ->label('Xuất Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(fn () => Excel::download(
                    new WarehouseStockCheckExport(
                        $this->getFilteredTableQuery(),
                        showPartnerColumn: false,
                    ),
                    'phieu-kiem-ke_' . now()->format('Y-m-d_His') . '.xlsx',
                )),

            CreateAction::make(),
        ];
    }
}

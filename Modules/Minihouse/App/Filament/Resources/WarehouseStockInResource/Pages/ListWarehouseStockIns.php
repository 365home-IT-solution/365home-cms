<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\WarehouseStockInResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Minihouse\App\Exports\WarehouseStockInExport;
use Modules\Minihouse\App\Filament\Resources\WarehouseStockInResource;

class ListWarehouseStockIns extends ListRecords
{
    protected static string $resource = WarehouseStockInResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Xuất Excel danh sách phiếu nhập ĐANG hiển thị trên bảng (bám theo bộ lọc/tìm kiếm
            // hiện tại, không phải toàn bộ).
            Action::make('exportStockInsExcel')
                ->label('Xuất Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(fn () => Excel::download(
                    new WarehouseStockInExport(
                        $this->getFilteredTableQuery(),
                        showPartnerColumn: false,
                    ),
                    'phieu-nhap-kho_' . now()->format('Y-m-d_His') . '.xlsx',
                )),

            CreateAction::make(),
        ];
    }
}

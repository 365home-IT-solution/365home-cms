<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockCheckResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Warehouse\App\Exports\WarehouseStockCheckExport;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockCheckResource;

class ListWarehouseStockCheck extends ListRecords
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
                        showPartnerColumn: auth()->user()?->isSuperAdmin() ?? false,
                    ),
                    'phieu-kiem-ke_' . now()->format('Y-m-d_His') . '.xlsx',
                )),

            CreateAction::make(),
        ];
    }
}

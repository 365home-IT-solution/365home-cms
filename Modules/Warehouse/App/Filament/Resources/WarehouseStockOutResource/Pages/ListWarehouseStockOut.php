<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Warehouse\App\Exports\WarehouseStockOutExport;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource;

class ListWarehouseStockOut extends ListRecords
{
    protected static string $resource = WarehouseStockOutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Xuất Excel danh sách phiếu xuất ĐANG hiển thị trên bảng (bám theo bộ lọc/tìm kiếm
            // hiện tại, không phải toàn bộ).
            Action::make('exportStockOutsExcel')
                ->label('Xuất Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(fn () => Excel::download(
                    new WarehouseStockOutExport(
                        $this->getFilteredTableQuery(),
                        showPartnerColumn: auth()->user()?->isSuperAdmin() ?? false,
                    ),
                    'phieu-xuat-kho_' . now()->format('Y-m-d_His') . '.xlsx',
                )),

            CreateAction::make(),
        ];
    }
}

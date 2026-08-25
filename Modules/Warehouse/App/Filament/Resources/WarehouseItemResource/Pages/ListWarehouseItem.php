<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseItemResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Warehouse\App\Exports\WarehouseItemExport;
use Modules\Warehouse\App\Filament\Resources\WarehouseItemResource;
use Modules\Warehouse\App\Filament\Support\WarehousePrinter;
use Modules\Warehouse\App\Models\WarehouseItem;

class ListWarehouseItem extends ListRecords
{
    protected static string $resource = WarehouseItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // In TOÀN BỘ vật tư đang có kèm số lượng tồn hiện tại — không phụ thuộc filter/tìm kiếm
            // đang áp dụng trên bảng, luôn xuất đủ danh sách (đúng yêu cầu "in toàn bộ vật tư").
            Action::make('printItemList')
                ->label('In danh sách tồn kho')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->action(fn () => WarehousePrinter::itemList(
                    WarehouseItem::query()->orderBy('name')->get(),
                    showPartnerColumn: auth()->user()?->isSuperAdmin() ?? false,
                )),

            // Ngược lại với nút in ở trên — xuất Excel LUÔN bám theo đúng bộ lọc/tìm kiếm đang áp
            // dụng trên bảng (getFilteredTableQuery()), để xem/phân tích đúng phần đang lọc.
            Action::make('exportItemsExcel')
                ->label('Xuất Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(fn () => Excel::download(
                    new WarehouseItemExport(
                        $this->getFilteredTableQuery(),
                        showPartnerColumn: auth()->user()?->isSuperAdmin() ?? false,
                    ),
                    'vat-tu_' . now()->format('Y-m-d_His') . '.xlsx',
                )),

            CreateAction::make(),
        ];
    }
}

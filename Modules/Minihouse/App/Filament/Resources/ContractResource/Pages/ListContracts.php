<?php

namespace Modules\Minihouse\App\Filament\Resources\ContractResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Minihouse\App\Filament\Exports\ContractExporter;
use Modules\Minihouse\App\Filament\Resources\ContractResource;

class ListContracts extends ListRecords
{
    protected static string $resource = ContractResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Xuất Excel TOÀN BỘ danh sách đang lọc/tìm kiếm hiện tại — xem chú thích chi tiết ở
            // ListTenants::getHeaderActions() (lý do dùng Actions\ExportAction cấp trang thay vì
            // Tables\Actions\ExportAction cấp bảng).
            Actions\ExportAction::make()->label('Xuất Excel')->exporter(ContractExporter::class),
            Actions\CreateAction::make(),
        ];
    }
}

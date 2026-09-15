<?php

namespace Modules\Minihouse\App\Filament\Resources\TransactionResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Minihouse\App\Filament\Exports\TransactionExporter;
use Modules\Minihouse\App\Filament\Resources\TransactionResource;

class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionResource::class;

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
            Actions\ExportAction::make()->label('Xuất Excel')->exporter(TransactionExporter::class),
            Actions\CreateAction::make(),
        ];
    }
}

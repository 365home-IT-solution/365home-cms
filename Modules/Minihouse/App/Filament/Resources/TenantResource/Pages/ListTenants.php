<?php

namespace Modules\Minihouse\App\Filament\Resources\TenantResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Minihouse\App\Filament\Exports\TenantExporter;
use Modules\Minihouse\App\Filament\Resources\TenantResource;

class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Xuất Excel TOÀN BỘ danh sách đang lọc/tìm kiếm hiện tại (khác ExportBulkAction ở
            // TenantTable — chỉ xuất đúng những dòng đã tick chọn) — yêu cầu người dùng 2026-09-12:
            // cần xuất được danh sách Khách thuê ra Excel để đối chiếu, trước đây chỉ có báo cáo tài
            // chính tổng hợp. Dùng Filament\Actions\ExportAction (action CẤP TRANG, đăng ký qua
            // getHeaderActions() giống CreateAction) — KHÔNG PHẢI Filament\Tables\Actions\ExportAction
            // (action cấp BẢNG qua $table->headerActions()) — thử cách đó trước nhưng nút không hiện
            // ra (ẩn bởi `display:none` inline do ListRecords::table() không đi qua đúng nhánh dựng
            // headerActions của InteractsWithTable), đây là cách đúng theo docs Filament.
            Actions\ExportAction::make()->label('Xuất Excel')->exporter(TenantExporter::class),
            Actions\CreateAction::make(),
        ];
    }
}

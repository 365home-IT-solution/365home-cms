<?php

namespace Modules\Minihouse\App\Filament\Exports;

use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Modules\Minihouse\App\Models\Invoice;

// Xuất Excel danh sách Hoá đơn — gắn qua ExportAction/ExportBulkAction ở InvoiceTable. Xuất số tiền
// dạng số nguyên thô (không qua Money::format() có "đ"/dấu chấm phân cách) để file Excel tính tổng/
// đối chiếu kế toán được ngay, không phải bóc tách lại chuỗi văn bản.
class InvoiceExporter extends Exporter
{
    protected static ?string $model = Invoice::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('contract.room.code')->label('Phòng'),
            ExportColumn::make('contract.tenant.fullname')->label('Khách thuê'),
            ExportColumn::make('month')->label('Tháng')->formatStateUsing(fn ($state) => $state?->format('m/Y')),
            ExportColumn::make('period_start')->label('Từ ngày')->formatStateUsing(fn ($state) => $state?->format('d/m/Y')),
            ExportColumn::make('period_end')->label('Đến ngày')->formatStateUsing(fn ($state) => $state?->format('d/m/Y')),
            ExportColumn::make('room_price')->label('Tiền phòng'),
            ExportColumn::make('electric_amount')->label('Tiền điện'),
            ExportColumn::make('water_amount')->label('Tiền nước'),
            ExportColumn::make('service_amount')->label('Phụ thu'),
            ExportColumn::make('total_amount')->label('Tổng tiền'),
            ExportColumn::make('amount_paid')->label('Đã trả'),
            ExportColumn::make('status')->label('Trạng thái')->formatStateUsing(fn (string $state) => match ($state) {
                Invoice::STATUS_PAID    => 'Đã thanh toán',
                Invoice::STATUS_PARTIAL => 'Thanh toán 1 phần',
                default                 => 'Chưa thanh toán',
            }),
            ExportColumn::make('paid_at')->label('Ngày thanh toán gần nhất')->formatStateUsing(fn ($state) => $state?->format('d/m/Y H:i')),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Đã xuất xong ' . number_format($export->successful_rows) . ' ' . str('hoá đơn')->plural($export->successful_rows) . '.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' dòng bị lỗi, không xuất được.';
        }

        return $body;
    }
}

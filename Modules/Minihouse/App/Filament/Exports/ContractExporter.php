<?php

namespace Modules\Minihouse\App\Filament\Exports;

use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Modules\Minihouse\App\Models\Contract;

// Xuất Excel danh sách Hợp đồng — gắn qua ExportAction/ExportBulkAction ở ContractTable.
class ContractExporter extends Exporter
{
    protected static ?string $model = Contract::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('room.code')->label('Phòng'),
            ExportColumn::make('room.building.name')->label('Toà nhà'),
            ExportColumn::make('tenant.fullname')->label('Khách thuê'),
            ExportColumn::make('tenant.phone')->label('SĐT khách thuê'),
            ExportColumn::make('start_date')->label('Ngày bắt đầu')->formatStateUsing(fn ($state) => $state?->format('d/m/Y')),
            ExportColumn::make('end_date')->label('Ngày kết thúc')->formatStateUsing(fn ($state) => $state?->format('d/m/Y')),
            ExportColumn::make('monthly_price')->label('Giá thuê / tháng'),
            ExportColumn::make('deposit_amount')->label('Tiền cọc'),
            ExportColumn::make('deposit_refunded_amount')->label('Tiền cọc đã hoàn'),
            ExportColumn::make('status')->label('Trạng thái')->formatStateUsing(fn (string $state) => match ($state) {
                Contract::STATUS_ACTIVE    => 'Đang hiệu lực',
                Contract::STATUS_EXPIRED   => 'Hết hạn',
                Contract::STATUS_CANCELLED => 'Đã huỷ',
                default => $state,
            }),
            ExportColumn::make('checkout_at')->label('Ngày trả phòng thực tế')->formatStateUsing(fn ($state) => $state?->format('d/m/Y')),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Đã xuất xong ' . number_format($export->successful_rows) . ' ' . str('hợp đồng')->plural($export->successful_rows) . '.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' dòng bị lỗi, không xuất được.';
        }

        return $body;
    }
}

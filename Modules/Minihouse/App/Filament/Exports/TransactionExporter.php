<?php

namespace Modules\Minihouse\App\Filament\Exports;

use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Modules\Minihouse\App\Models\Transaction;

// Xuất Excel sổ Thu Chi — gắn qua ExportAction/ExportBulkAction ở TransactionTable.
class TransactionExporter extends Exporter
{
    protected static ?string $model = Transaction::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('transaction_date')->label('Ngày giao dịch')->formatStateUsing(fn ($state) => $state?->format('d/m/Y')),
            ExportColumn::make('type')->label('Loại')->formatStateUsing(fn (string $state) => $state === Transaction::TYPE_IN ? 'Thu' : 'Chi'),
            ExportColumn::make('category')->label('Hạng mục')->formatStateUsing(fn (?string $state) => match ($state) {
                Transaction::CATEGORY_REPAIR         => 'Sửa chữa',
                Transaction::CATEGORY_OPERATION      => 'Vận hành',
                Transaction::CATEGORY_DEPOSIT_REFUND => 'Hoàn cọc',
                Transaction::CATEGORY_OTHER          => 'Khác',
                default => '',
            }),
            ExportColumn::make('amount')->label('Số tiền'),
            ExportColumn::make('building.name')->label('Toà nhà'),
            ExportColumn::make('contract.room.code')->label('Phòng liên quan'),
            ExportColumn::make('contract.tenant.fullname')->label('Khách thuê liên quan'),
            ExportColumn::make('note')->label('Ghi chú'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Đã xuất xong ' . number_format($export->successful_rows) . ' ' . str('giao dịch')->plural($export->successful_rows) . '.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' dòng bị lỗi, không xuất được.';
        }

        return $body;
    }
}

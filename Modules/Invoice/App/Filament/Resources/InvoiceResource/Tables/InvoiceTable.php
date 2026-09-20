<?php

declare(strict_types=1);

namespace Modules\Invoice\App\Filament\Resources\InvoiceResource\Tables;

use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Invoice\App\Filament\Support\InvoicePrinter;
use Modules\Invoice\App\Models\Invoice;

class InvoiceTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['order']))
            ->columns([
                TextColumn::make('order.order_code')
                    ->label('Mã đơn hàng')
                    ->searchable(),
                TextColumn::make('buyer_name')
                    ->label('Người mua')
                    ->searchable(),
                TextColumn::make('total_amount')
                    ->label('Tổng tiền')
                    ->money('VND')
                    ->sortable(),
                BadgeColumn::make('status')
                    ->label('Trạng thái')
                    ->formatStateUsing(fn (string $state) => Invoice::STATUS_LABELS[$state] ?? $state)
                    ->colors([
                        'warning' => Invoice::STATUS_DRAFT,
                        'success' => Invoice::STATUS_ISSUED,
                        'danger'  => Invoice::STATUS_ERROR,
                        'gray'    => Invoice::STATUS_CANCELLED,
                    ]),
                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->actions([
                EditAction::make()->label('Sửa'),
                Action::make('downloadDraft')
                    ->label('Tải PDF (bản nháp)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn (Invoice $record) => InvoicePrinter::draft($record)),
            ])
            ->defaultSort('created_at', 'desc');
    }
}

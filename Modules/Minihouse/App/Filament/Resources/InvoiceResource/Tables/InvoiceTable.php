<?php

namespace Modules\Minihouse\App\Filament\Resources\InvoiceResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Invoice;

class InvoiceTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contract.room.code')->label('Phòng')->searchable()->sortable(),
                TextColumn::make('contract.tenant.fullname')->label('Khách thuê')->searchable(),
                TextColumn::make('month')->label('Tháng')->date('m/Y')->sortable(),
                TextColumn::make('room_price')->label('Tiền phòng')->money('VND')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('electric_amount')->label('Tiền điện')->money('VND')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('water_amount')->label('Tiền nước')->money('VND')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_amount')->label('Tổng tiền')->money('VND')->sortable(),
                TextColumn::make('status')->label('Trạng thái')->badge()->formatStateUsing(fn (string $state) => match ($state) {
                    Invoice::STATUS_PAID => 'Đã thanh toán',
                    default => 'Chưa thanh toán',
                })->color(fn (string $state) => $state === Invoice::STATUS_PAID ? 'success' : 'warning'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([
                        Invoice::STATUS_UNPAID => 'Chưa thanh toán',
                        Invoice::STATUS_PAID   => 'Đã thanh toán',
                    ]),
                Filter::make('month')
                    ->label('Tháng hoá đơn')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Từ tháng')->displayFormat('m/Y'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Đến tháng')->displayFormat('m/Y'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('month', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('month', '<=', $date));
                    }),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('month', 'desc')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}

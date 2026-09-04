<?php

namespace Modules\Minihouse\App\Filament\Resources\TransactionResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Transaction;

class TransactionTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_date')->label('Ngày')->date('d/m/Y')->sortable(),
                TextColumn::make('type')->label('Loại')->badge()->formatStateUsing(fn (string $state) => $state === Transaction::TYPE_IN ? 'Thu' : 'Chi')
                    ->color(fn (string $state) => $state === Transaction::TYPE_IN ? 'success' : 'danger'),
                TextColumn::make('category')->label('Hạng mục')->formatStateUsing(fn (?string $state) => match ($state) {
                    Transaction::CATEGORY_REPAIR    => 'Sửa chữa',
                    Transaction::CATEGORY_OPERATION => 'Vận hành',
                    Transaction::CATEGORY_OTHER     => 'Khác',
                    default => '—',
                }),
                TextColumn::make('amount')->label('Số tiền')->money('VND')->sortable(),
                TextColumn::make('contract.room.code')->label('Phòng')->searchable(),
                TextColumn::make('note')->label('Ghi chú')->limit(40)->searchable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Loại')
                    ->options([
                        Transaction::TYPE_IN  => 'Thu',
                        Transaction::TYPE_OUT => 'Chi',
                    ]),
                SelectFilter::make('category')
                    ->label('Hạng mục')
                    ->options([
                        Transaction::CATEGORY_REPAIR    => 'Sửa chữa',
                        Transaction::CATEGORY_OPERATION => 'Vận hành',
                        Transaction::CATEGORY_OTHER     => 'Khác',
                    ]),
                Filter::make('transaction_date')
                    ->label('Khoảng ngày')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Từ ngày'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Đến ngày'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('transaction_date', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('transaction_date', '<=', $date));
                    }),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}

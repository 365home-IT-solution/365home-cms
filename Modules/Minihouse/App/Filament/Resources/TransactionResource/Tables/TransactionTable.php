<?php

namespace Modules\Minihouse\App\Filament\Resources\TransactionResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
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
                TextColumn::make('amount')->label('Số tiền')->money('VND')->sortable(),
                TextColumn::make('contract.room.code')->label('Phòng'),
                TextColumn::make('note')->label('Ghi chú')->limit(40),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}

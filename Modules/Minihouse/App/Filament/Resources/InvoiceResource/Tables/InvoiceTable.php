<?php

namespace Modules\Minihouse\App\Filament\Resources\InvoiceResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Invoice;

class InvoiceTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contract.room.code')->label('Phòng')->searchable(),
                TextColumn::make('contract.tenant.fullname')->label('Khách thuê')->searchable(),
                TextColumn::make('month')->label('Tháng')->date('m/Y'),
                TextColumn::make('total_amount')->label('Tổng tiền')->money('VND')->sortable(),
                TextColumn::make('status')->label('Trạng thái')->badge()->formatStateUsing(fn (string $state) => match ($state) {
                    Invoice::STATUS_PAID => 'Đã thanh toán',
                    default => 'Chưa thanh toán',
                })->color(fn (string $state) => $state === Invoice::STATUS_PAID ? 'success' : 'warning'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}

<?php

namespace Modules\Minihouse\App\Filament\Resources\ContractResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Contract;

class ContractTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('room.code')->label('Phòng')->searchable(),
                TextColumn::make('tenant.fullname')->label('Khách thuê')->searchable(),
                TextColumn::make('start_date')->label('Bắt đầu')->date('d/m/Y'),
                TextColumn::make('end_date')->label('Kết thúc')->date('d/m/Y'),
                TextColumn::make('monthly_price')->label('Giá thuê')->money('VND'),
                TextColumn::make('status')->label('Trạng thái')->badge()->formatStateUsing(fn (string $state) => match ($state) {
                    Contract::STATUS_ACTIVE    => 'Đang hiệu lực',
                    Contract::STATUS_EXPIRED   => 'Hết hạn',
                    Contract::STATUS_CANCELLED => 'Đã huỷ',
                    default => $state,
                })->color(fn (string $state) => match ($state) {
                    Contract::STATUS_ACTIVE    => 'success',
                    Contract::STATUS_EXPIRED   => 'gray',
                    Contract::STATUS_CANCELLED => 'danger',
                    default => 'gray',
                }),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}

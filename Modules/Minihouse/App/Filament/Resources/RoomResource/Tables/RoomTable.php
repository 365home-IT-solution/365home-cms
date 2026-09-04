<?php

namespace Modules\Minihouse\App\Filament\Resources\RoomResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Room;

class RoomTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Mã / Tên phòng')->searchable(),
                TextColumn::make('building.name')->label('Toà nhà')->searchable(),
                TextColumn::make('price')->label('Giá thuê')->money('VND')->sortable(),
                TextColumn::make('status')->label('Trạng thái')->badge()->formatStateUsing(fn (string $state) => match ($state) {
                    Room::STATUS_EMPTY  => 'Trống',
                    Room::STATUS_RENTED => 'Đang thuê',
                    Room::STATUS_REPAIR => 'Bảo trì',
                    default => $state,
                })->color(fn (string $state) => match ($state) {
                    Room::STATUS_EMPTY  => 'success',
                    Room::STATUS_RENTED => 'warning',
                    Room::STATUS_REPAIR => 'danger',
                    default => 'gray',
                }),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}

<?php

namespace Modules\Minihouse\App\Filament\Resources\TenantResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TenantTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fullname')->label('Họ tên')->searchable(),
                TextColumn::make('phone')->label('Điện thoại')->searchable(),
                TextColumn::make('id_card_number')->label('CCCD/CMND'),
                TextColumn::make('room.code')->label('Phòng đang ở'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}

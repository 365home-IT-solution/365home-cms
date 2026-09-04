<?php

namespace Modules\Minihouse\App\Filament\Resources\BuildingResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BuildingTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Tên toà nhà')->searchable(),
                TextColumn::make('address')->label('Địa chỉ')->searchable(),
                TextColumn::make('rooms_count')->label('Số phòng')->counts('rooms'),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}

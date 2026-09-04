<?php

namespace Modules\Minihouse\App\Filament\Resources\AmenityResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AmenityTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Tên tiện ích')->searchable()->sortable(),
                TextColumn::make('rooms_count')->label('Số phòng dùng')->counts('rooms')->sortable(),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('name')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}

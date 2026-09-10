<?php

namespace Modules\Minihouse\App\Filament\Resources\ZoneResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ZoneTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Tên khu vực')->searchable()->sortable(),
                TextColumn::make('buildings_count')->label('Số toà nhà')->counts('buildings')->sortable(),
                TextColumn::make('note')->label('Ghi chú')->limit(50),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}

<?php

namespace Modules\Minihouse\App\Filament\Resources\AnnouncementResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AnnouncementTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Tiêu đề')->searchable()->limit(60),
                TextColumn::make('building.name')->label('Gửi cho')->placeholder('Tất cả toà nhà')->sortable(),
                TextColumn::make('createdBy.fullname')->label('Người đăng')->placeholder('—'),
                TextColumn::make('created_at')->label('Ngày đăng')->dateTime('d/m/Y H:i')->sortable(),
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

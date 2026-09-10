<?php

namespace Modules\Minihouse\App\Filament\Resources\BuildingResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Zone;

class BuildingTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')->label('Ảnh')->circular(),
                TextColumn::make('name')->label('Tên toà nhà')->searchable()->sortable(),
                TextColumn::make('zone.name')->label('Khu vực')->badge()->color('gray')->placeholder('—')->sortable(),
                TextColumn::make('address')->label('Địa chỉ')->searchable(),
                TextColumn::make('rooms_count')->label('Số phòng')->counts('rooms')->sortable(),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable(),
            ])
            ->filters([
                // withoutGlobalScopes() — Zone hiện không có global scope riêng, giữ để nhất quán và
                // an toàn nếu sau này Zone được thêm scope tương tự Building.
                SelectFilter::make('zone_id')
                    ->label('Khu vực')
                    ->options(fn () => Zone::withoutGlobalScopes()->pluck('name', 'id')),
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

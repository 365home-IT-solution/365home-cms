<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\CameraResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

// Mirror ĐÚNG App\Filament\Resources\CameraResource::table() (Home), chỉ đổi cột "Chi nhánh" thành
// "Toà nhà" — cùng dùng quan hệ branch() có sẵn trên Camera (BelongsTo Category, MiniHouse Building
// CŨNG LÀ 1 Category nên quan hệ này hoạt động đúng không cần sửa gì ở model).
class CameraTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Tên camera')->searchable(),
                TextColumn::make('stream_key')->label('Stream key')->fontFamily('mono'),
                TextColumn::make('branch.name')->label('Toà nhà')->toggleable(),
                ToggleColumn::make('status')->label('Hoạt động'),
                TextColumn::make('created_at')->label('Tạo lúc')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }
}

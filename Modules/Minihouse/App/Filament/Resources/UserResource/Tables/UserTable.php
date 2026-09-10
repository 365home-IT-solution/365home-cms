<?php

namespace Modules\Minihouse\App\Filament\Resources\UserResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fullname')->label('Họ tên')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable()->sortable(),
                TextColumn::make('phone')->label('Điện thoại')->searchable(),
                TextColumn::make('roles.name')->label('Vai trò')->badge(),
                TextColumn::make('minihouseBuildings.name')->label('Toà nhà quản lý')->badge()->placeholder('Tất cả'),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->searchable();
    }
}

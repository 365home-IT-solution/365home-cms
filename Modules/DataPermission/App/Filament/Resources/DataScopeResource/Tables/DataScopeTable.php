<?php

declare(strict_types=1);

namespace Modules\DataPermission\App\Filament\Resources\DataScopeResource\Tables;

use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DataScopeTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.fullname')
                    ->label('Người dùng')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->color('gray'),

                TextColumn::make('table_name')
                    ->label('Bảng dữ liệu')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info'),

                IconColumn::make('can_view')
                    ->label('Được xem')
                    ->boolean()
                    ->alignCenter(),

                IconColumn::make('scope_own_data')
                    ->label('Chỉ dữ liệu của mình')
                    ->boolean()
                    ->alignCenter(),

                TextColumn::make('owner_column')
                    ->label('Cột chủ sở hữu')
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('notes')
                    ->label('Ghi chú')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('can_view')
                    ->label('Được xem'),

                TernaryFilter::make('scope_own_data')
                    ->label('Chỉ dữ liệu của mình'),

                SelectFilter::make('table_name')
                    ->label('Bảng dữ liệu')
                    ->searchable(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}

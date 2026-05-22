<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomAmenityResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RoomAmenityTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->width(60),

                TextColumn::make('amenity_type')
                    ->label('Nhóm')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                TextColumn::make('name')
                    ->label('Tên tiện ích')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('icon')
                    ->label('Icon')
                    ->badge()
                    ->color('info'),

                TextColumn::make('sort_order')
                    ->label('Thứ tự')
                    ->sortable()
                    ->width(80),

                ToggleColumn::make('status')
                    ->label('Kích hoạt'),

                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('amenity_type')
                    ->label('Nhóm tiện ích')
                    ->options(fn () => \Modules\Product\App\Models\RoomAmenity::distinct()->pluck('amenity_type', 'amenity_type')->toArray()),
            ])
            ->defaultSort('amenity_type')
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->reorderable('sort_order');
    }
}

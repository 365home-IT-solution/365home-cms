<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomTypeResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Modules\Product\App\Filament\Resources\RoomTypeResource\Actions\AssignProductsAction;

class RoomTypeTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->width(60),

                TextColumn::make('name')
                    ->label(__('product::room_type.table.label.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->label(__('product::room_type.table.label.slug'))
                    ->searchable()
                    ->copyable()
                    ->color('gray'),

                TextColumn::make('icon')
                    ->label(__('product::room_type.table.label.icon'))
                    ->badge()
                    ->color('info'),

                TextColumn::make('sort_order')
                    ->label(__('product::room_type.table.label.sort_order'))
                    ->sortable()
                    ->width(80),

                ToggleColumn::make('is_active')
                    ->label(__('product::room_type.table.label.is_active')),

                TextColumn::make('created_at')
                    ->label(__('product::room_type.table.label.created_at'))
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->actions([
                AssignProductsAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->reorderable('sort_order');
    }
}

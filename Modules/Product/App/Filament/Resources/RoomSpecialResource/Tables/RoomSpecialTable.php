<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomSpecialResource\Tables;

use App\Filament\Support\PartnerTableHelpers;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RoomSpecialTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->width(60),

                TextColumn::make('product.name')
                    ->label('Phòng')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('icon')
                    ->label('Icon')
                    ->badge()
                    ->color('info'),

                TextColumn::make('title')
                    ->label('Tiêu đề')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('short_description')
                    ->label('Mô tả ngắn')
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('sort_order')
                    ->label('Thứ tự')
                    ->sortable()
                    ->width(80),

                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                PartnerTableHelpers::column('product.partner.name'),
            ])
            ->filters([
                SelectFilter::make('product_id')
                    ->label('Phòng')
                    ->relationship('product', 'name'),
                PartnerTableHelpers::filterThroughRelation('product'),
            ])
            ->defaultSort('product_id')
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->reorderable('sort_order');
    }
}

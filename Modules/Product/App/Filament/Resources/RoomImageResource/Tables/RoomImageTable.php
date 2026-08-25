<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomImageResource\Tables;

use App\Filament\Support\PartnerTableHelpers;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RoomImageTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('url')
                    ->label('Ảnh')
                    ->getStateUsing(fn ($record): string => $record->url)
                    ->height(60)
                    ->width(100),

                TextColumn::make('room.name')
                    ->label('Phòng')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Loại')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'main' ? 'success' : 'info')
                    ->formatStateUsing(fn (string $state): string => $state === 'main' ? 'Ảnh chính' : 'Ảnh phụ'),

                TextColumn::make('disk')
                    ->label('Disk')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('alt')
                    ->label('Alt (SEO)')
                    ->limit(40)
                    ->placeholder('—'),

                TextColumn::make('sort_order')
                    ->label('Thứ tự')
                    ->sortable()
                    ->width(80),

                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                PartnerTableHelpers::column('room.partner.name'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Loại ảnh')
                    ->options([
                        'main'    => 'Ảnh chính',
                        'gallery' => 'Ảnh phụ',
                    ]),

                SelectFilter::make('room_id')
                    ->label('Phòng')
                    ->relationship('room', 'name'),

                PartnerTableHelpers::filterThroughRelation('room'),
            ])
            ->defaultSort('sort_order')
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->reorderable('sort_order');
    }
}

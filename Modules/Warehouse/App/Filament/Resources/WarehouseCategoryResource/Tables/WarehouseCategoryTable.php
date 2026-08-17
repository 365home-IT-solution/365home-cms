<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseCategoryResource\Tables;

use App\Filament\Support\PartnerTableHelpers;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WarehouseCategoryTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Tên nhóm')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('items_count')
                    ->label('Số vật tư')
                    ->counts('items'),

                PartnerTableHelpers::column(),
            ])
            ->filters([
                PartnerTableHelpers::filter(),
            ])
            ->defaultSort('name')
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }
}

<?php

namespace Modules\Minihouse\App\Filament\Resources\SurchargeResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Support\Money;

class SurchargeTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('building.name')->label('Toà nhà')->searchable()->sortable(),
                TextColumn::make('name')->label('Tên phụ thu')->searchable()->sortable(),
                TextColumn::make('amount')->label('Số tiền mặc định')->formatStateUsing(fn ($state) => Money::format($state))->sortable(),
                IconColumn::make('is_active')->label('Đang áp dụng')->boolean(),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('building_id')
                    ->label('Toà nhà')
                    ->options(fn () => Building::pluck('name', 'id')),
            ])
            ->actions([EditAction::make(), DeleteAction::make()])
            ->bulkActions([DeleteBulkAction::make()])
            ->defaultSort('building_id')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}

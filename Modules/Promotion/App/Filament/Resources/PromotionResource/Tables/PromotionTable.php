<?php

declare(strict_types=1);

namespace Modules\Promotion\App\Filament\Resources\PromotionResource\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Modules\Promotion\App\Filament\Resources\PromotionResource\Tables\Actions\PromotionAction;
use Modules\Promotion\App\Filament\Resources\PromotionResource\Tables\BulkActions\PromotionBulkAction;
use Modules\Promotion\App\Filament\Resources\PromotionResource\Tables\Filters\PromotionFilter;

class PromotionTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Tên khuyến mãi')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Loại')
                    ->formatStateUsing(function (string $state) {
                        if ($state === 'tuimu') {
                            return '<img src="' . asset('storage/tuimu.png') . '" alt="Túi mù" style="width: 24px; height: 24px;" />';
                        }
                        return $state === 'fixed' ? 'Giảm cố định' : 'Giảm %';
                    })
                    ->html(),

                TextColumn::make('value')
                    ->label('Giá trị')
                    ->formatStateUsing(function ($state, $record) {
                        if ($record->type === 'percentage') {
                            return is_numeric($state) ? (float) $state : $state; // Ensure numeric for percentage
                        }
                        // Convert to float for non-percentage types, default to 0 if non-numeric
                        $numericValue = is_numeric($state) ? (float) $state : 0;
                        return number_format($numericValue, 0, ',', '.'); // Format as Vietnamese currency
                    })
                    ->suffix(fn ($record) => $record->type === 'percentage' ? '%' : ' đ'),

                TextColumn::make('start_at')
                    ->label('Bắt đầu')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('end_at')
                    ->label('Kết thúc')
                    ->dateTime()
                    ->sortable(),

                ToggleColumn::make('is_active')
                    ->label('Kích hoạt'),

                TextColumn::make('creator.fullname')
                    ->label('Người tạo')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters(PromotionFilter::filter())
            ->actions(PromotionAction::action())
            ->bulkActions(PromotionBulkAction::bulkActions());
    }
}

<?php

declare(strict_types=1);

namespace Modules\Themestudio\App\Filament\Resources\ThemeStudioResource\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Themestudio\App\Filament\Resources\ThemeStudioResource\Tables\Actions\ThemeStudioAction;
use Modules\Themestudio\App\Filament\Resources\ThemeStudioResource\Tables\BulkActions\ThemeStudioBulkAction;
use Modules\Themestudio\App\Filament\Resources\ThemeStudioResource\Tables\Filters\ThemeStudioFilter;

class ThemeStudioTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Tên Theme')
            ])
            ->defaultSort('created_at', 'desc')
            ->filters(ThemeStudioFilter::filter())
            ->actions(ThemeStudioAction::action())
            ->bulkActions(ThemeStudioBulkAction::bulkActions());
    }
}

<?php

declare(strict_types=1);

namespace Modules\QA\App\Filament\Resources\QAResource\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\QA\App\Filament\Resources\QAResource\Tables\Actions\QAAction;
use Modules\QA\App\Filament\Resources\QAResource\Tables\BulkActions\QABulkAction;
use Modules\QA\App\Filament\Resources\QAResource\Tables\Filters\QAFilter;

class QATable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Ngày thêm')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters(QAFilter::filter())
            ->actions(QAAction::action())
            ->bulkActions(QABulkAction::bulkActions());
    }
}
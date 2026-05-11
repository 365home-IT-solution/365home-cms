<?php

declare(strict_types=1);

namespace Modules\Process\App\Filament\Resources\ProcessResource\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Process\App\Filament\Resources\ProcessResource\Tables\Actions\ProcessAction;
use Modules\Process\App\Filament\Resources\ProcessResource\Tables\BulkActions\ProcessBulkAction;

class ProcessTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('process::process.table.label.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('steps_count')
                    ->label(__('process::process.table.label.steps_count'))
                    ->counts('steps')
                    ->badge()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('process::process.table.label.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions(ProcessAction::action())
            ->bulkActions(ProcessBulkAction::bulkActions());
    }
}

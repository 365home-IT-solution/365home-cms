<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources\BookResource\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Book\App\Filament\Resources\BookResource\Tables\Actions\BookAction;
use Modules\Book\App\Filament\Resources\BookResource\Tables\BulkActions\BookBulkAction;
use Modules\Book\App\Filament\Resources\BookResource\Tables\Filters\BookFilter;

class BookTable
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
            ->filters(BookFilter::filter())
            ->actions(BookAction::action())
            ->bulkActions(BookBulkAction::bulkActions());
    }
}
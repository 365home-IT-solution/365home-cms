<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources\CategoryResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $title = 'Phòng đã gán';

    protected static ?string $label = 'phòng';

    protected static ?string $pluralLabel = 'Phòng đã gán';

    public function form(Form $form): Form
    {
        return $form->schema([
            Toggle::make('is_activated')
                ->label('Kích hoạt'),

            Toggle::make('is_in_stock')
                ->label('Còn phòng'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->width('50px')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Tên phòng')
                    ->searchable()
                    ->sortable()
                    ->limit(50),

                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_activated')
                    ->label('Kích hoạt')
                    ->boolean()
                    ->alignCenter(),

                IconColumn::make('is_in_stock')
                    ->label('Còn phòng')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->actions([
                EditAction::make()->label('Sửa'),
            ])
            ->bulkActions([])
            ->paginated([25, 50, 100]);
    }
}

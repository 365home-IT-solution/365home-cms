<?php

namespace Modules\Product\App\Filament\Resources\ProductResource\Tables;

use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\SpatieTagsColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Modules\Product\App\Filament\Resources\ProductResource\Tables\Actions\ProductAction;
use Modules\Product\App\Filament\Resources\ProductResource\Tables\BulkActions\ProductBulkAction;
use Modules\Product\App\Filament\Resources\ProductResource\Tables\Filters\ProductFilter;
use Modules\Product\App\Models\Product;

class ProductTable extends Table
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('media')
                    ->label(__('product::product.table.label.product_image'))
                    ->collection('Ảnh bìa'),
                TextColumn::make('name')
                    ->label(__('product::product.table.label.name'))
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('url')
                    ->label(__('product::product.table.label.url'))
                    ->getStateUsing(function (Product $record): string {
                        return url("/room/{$record->slug}");
                    })
                    ->copyable()
                    ->wrap()
                    ->openUrlInNewTab(),
//                SpatieTagsColumn::make('tags')
//                    ->label(__('product::product.table.label.tags'))
//                    ->type('categories')
//                    ->colors(['secondary']),
                TextColumn::make('categories.name')
                    ->label(__('product::product.table.label.categories'))
                    ->searchable(),
                TextColumn::make('lock_id')
                    ->label('Khóa ngoài (check-in)')
                    ->placeholder('Chưa gán')
                    ->icon('heroicon-o-key')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('lock_id_checkout')
                    ->label('Khóa trong (check-out)')
                    ->placeholder('Chưa gán')
                    ->icon('heroicon-o-key')
                    ->badge()
                    ->color(fn ($state) => $state ? 'warning' : 'gray')
                    ->sortable(),
                ToggleColumn::make('is_activated')
                    ->label(__('product::product.table.label.is_activated'))
                    ->tooltip(function ($record) {
                        return $record->is_activated
                            ? __('product::product.table.options.active')
                            : __('product::product.table.options.inactive');
                    })
                    ->onIcon(__('product::product.table.icons.active'))
                    ->offIcon(__('product::product.table.icons.inactive'))
                    ->alignCenter()
                    ->sortable(),
//                ToggleColumn::make('is_trend')
//                    ->label(__('product::product.table.label.is_trend'))
//                    ->tooltip(function ($record) {
//                        return $record->is_trend
//                            ? __('product::product.table.options.active')
//                            : __('product::product.table.options.inactive');
//                    })
//                    ->onIcon(__('product::product.table.icons.active'))
//                    ->offIcon(__('product::product.table.icons.inactive'))
//                    ->alignCenter()
//                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('product::product.table.label.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters(ProductFilter::filter())
            ->actions(ProductAction::action())
            ->bulkActions(ProductBulkAction::bulkActions());
    }
}

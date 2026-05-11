<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ProductResource\Tables\Filters;

use Filament\Tables\Filters\SelectFilter;
use Modules\Category\Entities\Category;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;

class ProductFilter
{
    public static function filter(): array
    {
        return [
            SelectFilter::make('categories')
                ->label(__('product::product.filter.label.categories'))
                ->preload()
                ->options(Category::where('category_type', 'product')->pluck('name', 'id')->toArray())
                ->query(function ($query, $state) {
                    if ($state) {
                        if ($state['value'] == null) {
                            $query->whereHas('categories', function ($query) use ($state) {
                                $query->whereIn('categories.id', Category::all()->pluck('id')->toArray());
                            });
                        } else {
                            $query->whereHas('categories', function ($query) use ($state) {
                                $query->whereIn('categories.id', $state);
                            });
                        }
                    }
                }),

            Filter::make('created_at')
                ->label(__('product::product.filter.label.created_at'))
                ->form([
                    DatePicker::make('created_from')
                        ->label(__('product::product.filter.label.created_from')),
                    DatePicker::make('created_until')
                        ->label(__('product::product.filter.label.created_until')),
                ])
                ->query(function ($query, array $data) {
                    if ($data['created_from']) {
                        $query->whereDate('created_at', '>=', $data['created_from']);
                    }

                    if ($data['created_until']) {
                        $query->whereDate('created_at', '<=', $data['created_until']);
                    }

                    return $query;
                }),

            SelectFilter::make('is_activated')
                ->label(__('product::product.filter.label.is_activated'))
                ->options([
                    true => __('product::product.filter.options.active'),
                    false => __('product::product.filter.options.inactive'),
                ]),

            SelectFilter::make('is_trend')
                ->label(__('product::product.filter.label.is_trend'))
                ->options([
                    true => __('product::product.filter.options.active'),
                    false => __('product::product.filter.options.inactive'),
                ]),
        ];
    }
}

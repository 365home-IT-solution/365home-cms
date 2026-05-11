<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentProductResource\Tables\Filters;

use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\DatePicker;
class CommentProductFilter
{
    public static function fillter()
    {
        return [
            SelectFilter::make('pin')
                ->label(__('comment::comment-product.filter.label.pin'))
                ->options([
                    true => __('comment::comment-product.filter.options.pinOn'),
                    false => __('comment::comment-product.filter.options.pinOff'),
                ])->columnSpan(12),
            Filter::make('created_at')
                ->label(__('comment::comment-product.filter.label.created_at'))
                ->form([
                    DatePicker::make('Từ')->columnSpan(12),
                    DatePicker::make('Đến')->columnSpan(12),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    return $query
                        ->when(
                            $data['Từ'],
                            fn(Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                        )
                        ->when(
                            $data['Đến'],
                            fn(Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                        );
                })->columns(12)
        ];
    }
}

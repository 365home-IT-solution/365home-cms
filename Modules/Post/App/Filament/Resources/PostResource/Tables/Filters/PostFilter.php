<?php

declare(strict_types=1);

namespace Modules\Post\App\Filament\Resources\PostResource\Tables\Filters;

use Filament\Tables\Filters\SelectFilter;
use Modules\Category\Entities\Category;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;

class PostFilter
{
    public static function filter(): array
    {
        return [
            SelectFilter::make('categories')
                ->label(__('post::post.filter.label.categories'))
                ->preload()
                ->options(
                    Category::where('category_type', 'post')
                        ->pluck('name', 'id')
                        ->toArray()
                )
                ->query(function ($query, $state) {
                    if ($state) {
                        if ($state['value'] == null) {
                            $query->whereHas('categories', function ($query) {
                                $query->where('category_type', 'post');
                            });
                        } else {
                            $query->whereHas('categories', function ($query) use ($state) {
                                $query->whereIn('categories.id', $state)
                                      ->where('category_type', 'post');
                            });
                        }
                    }
                }),

            Filter::make('created_at')
                ->label(__('post::post.filter.label.created_at'))
                ->form([
                    DatePicker::make('created_from')
                        ->label(__('post::post.filter.label.created_from')),
                    DatePicker::make('created_until')
                        ->label(__('post::post.filter.label.created_until')),
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

            SelectFilter::make('status')
                ->label(__('post::post.filter.label.status'))
                ->options([
                    'draft' => __('post::post.table.status.draft'),
                    'archived' => __('post::post.table.status.archived'),
                    'published' => __('post::post.table.status.published'),
                    'scheduled' => __('post::post.table.status.scheduled')
                ])
                ->query(function ($query, array $state) {
                    if (!$state['value']) {
                        return $query;
                    }

                    return match ($state['value']) {
                        'scheduled' => $query->where('status', 'published')
                            ->whereNotNull('published_at')
                            ->where('published_at', '>', now()),
                        'published' => $query->where('status', 'published')
                            ->where(function ($query) {
                                $query->whereNull('published_at')
                                    ->orWhere('published_at', '<=', now());
                            }),
                        default => $query->where('status', $state['value']),
                    };
                })
        ];
    }
}

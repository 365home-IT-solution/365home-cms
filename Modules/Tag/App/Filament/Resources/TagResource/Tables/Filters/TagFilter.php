<?php

declare(strict_types=1);

namespace Modules\Tag\App\Filament\Resources\TagResource\Tables\Filters;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;

class TagFilter
{
    /**
     * @throws \Exception
     */
    public static function filter(): array
    {
        return [
            Filter::make('created_at')
                ->label(__('tag::tag.filter.label.created_at'))
                ->form([
                    DatePicker::make('created_from')
                        ->label(__('tag::tag.filter.label.created_from')),
                    DatePicker::make('created_until')
                        ->label(__('tag::tag.filter.label.created_until')),
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
        ];
    }
}

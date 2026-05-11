<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\FormResource\Tables\Filters;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;

class FormFilter
{
    public static function filter(): array
    {
        return [
            Filter::make('created_at')
                ->label(__('form::form.filter.label.created_at'))
                ->form([
                    DatePicker::make('created_from')
                        ->label(__('form::form.filter.label.created_from')),
                    DatePicker::make('created_until')
                        ->label(__('form::form.filter.label.created_until')),
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

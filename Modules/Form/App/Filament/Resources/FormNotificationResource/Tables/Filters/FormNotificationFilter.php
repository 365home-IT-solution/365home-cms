<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\FormNotificationResource\Tables\Filters;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;

class FormNotificationFilter
{
    public static function filter(): array
    {
        return [
            Filter::make('created_at')
                ->label(__('form::form-notification.filter.label.created_at'))
                ->form([
                    DatePicker::make('created_from')
                        ->label(__('form::form-notification.filter.label.created_from')),
                    DatePicker::make('created_until')
                        ->label(__('form::form-notification.filter.label.created_until')),
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
                ->label(__('form::form-notification.filter.label.status'))
                ->options([
                    'active' => __('form::form-notification.filter.options.active'),
                    'inactive' => __('form::form-notification.filter.options.inactive'),
                ]),
        ];
    }
}

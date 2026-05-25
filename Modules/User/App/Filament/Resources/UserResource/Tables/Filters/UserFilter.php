<?php

namespace Modules\User\App\Filament\Resources\UserResource\Tables\Filters;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Illuminate\Contracts\Database\Eloquent\Builder;

class UserFilter
{
    public static function filter()
    {
        return [
            Filter::make('created_at')
                ->label('Ngày tạo')
                ->form([
                    DatePicker::make('created_from')
                        ->label('Từ ngày'),
                    DatePicker::make('created_until')
                        ->label('Đến ngày'),
                ])
                ->query(function ($query, array $data) {
                    return $query
                        ->when(
                            $data['created_from'],
                            fn($query) => $query->whereDate('created_at', '>=', $data['created_from'])
                        )
                        ->when(
                            $data['created_until'],
                            fn($query) => $query->whereDate('created_at', '<=', $data['created_until'])
                        );
                })
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\SettingCompany\App\Filament\Resources\SettingCompanyResource\Tables\Filters;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;

class SettingCompanyFilter
{
    public static function filter(): array
    {
        return [
            Filter::make('created_at')
                ->label(__('settingcompany::setting_company.filter.label.created_at'))
                ->form([
                    DatePicker::make('created_from')
                        ->label(__('settingcompany::setting_company.filter.label.created_from')),
                    DatePicker::make('created_until')
                        ->label(__('settingcompany::setting_company.filter.label.created_until')),
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
                ->label(__('settingcompany::setting_company.filter.label.status'))
                ->options([
                    'active' => __('settingcompany::setting_company.filter.options.active'),
                    'inactive' => __('settingcompany::setting_company.filter.options.inactive'),
                ]),
        ];
    }
}

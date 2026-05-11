<?php
declare(strict_types=1);
namespace Modules\SettingCompany\App\Filament\Resources\SettingCompanyResource\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\SettingCompany\App\Filament\Resources\SettingCompanyResource\Tables\Actions\SettingCompanyAction;
use Modules\SettingCompany\App\Filament\Resources\SettingCompanyResource\Tables\BulkActions\SettingCompanyBulkAction;
use Modules\SettingCompany\App\Filament\Resources\SettingCompanyResource\Tables\Filters\SettingCompanyFilter;

class SettingCompanyTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('settingcompany::setting_company.table.columns.name'))
                    ->sortable(),
                TextColumn::make('tax_code')
                    ->label(__('settingcompany::setting_company.table.columns.tax_code'))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('settingcompany::setting_company.table.columns.created_at'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('settingcompany::setting_company.table.columns.updated_at'))
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters(SettingCompanyFilter::filter())
            ->actions(SettingCompanyAction::action())
            ->bulkActions(SettingCompanyBulkAction::bulkActions())
            ->defaultSort('updated_at', 'desc');
    }
}

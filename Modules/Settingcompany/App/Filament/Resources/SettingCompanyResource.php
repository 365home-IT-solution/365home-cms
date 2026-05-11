<?php

namespace Modules\SettingCompany\App\Filament\Resources;

use Modules\SettingCompany\App\Filament\Resources\SettingCompanyResource\Forms\SettingCompanyForm;
use Modules\SettingCompany\App\Filament\Resources\SettingCompanyResource\Tables\SettingCompanyTable;
use Modules\SettingCompany\App\Filament\Resources\SettingCompanyResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\SettingCompany\Entities\Business;

class SettingCompanyResource extends Resource
{
    protected static ?string $model = Business::class;
    
    public static function getNavigationIcon(): string
    {
        return __('settingcompany::setting_company.resource.navigation_icon');
    }
    public static function getNavigationGroup(): ?string
    {
        return __('settingcompany::setting_company.resource.navigation_group');
    }
    public static function getNavigationLabel(): string
    {
        return __('settingcompany::setting_company.resource.navigation_label');
    }
    public static function getModelLabel(): string
    {
        return __('settingcompany::setting_company.resource.model_label');
    }
    public static function getPluralModelLabel(): string
    {
        return __('settingcompany::setting_company.resource.plural_model_label');
    }
    
    public static function form(Form $form): Form
    {
        return SettingCompanyForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return SettingCompanyTable::table($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\EditSettingCompany::route('/edit'),
        ];
    }
}
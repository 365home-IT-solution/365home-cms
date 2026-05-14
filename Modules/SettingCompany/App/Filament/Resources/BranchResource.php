<?php

namespace Modules\SettingCompany\App\Filament\Resources;

use Modules\SettingCompany\App\Filament\Resources\BranchResource\Forms\BranchForm;
use Modules\SettingCompany\App\Filament\Resources\BranchResource\Tables\BranchTable;
use Modules\SettingCompany\App\Filament\Resources\BranchResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\SettingCompany\Entities\Branch as EntitiesBranch;

class BranchResource extends Resource
{
    protected static ?string $model = EntitiesBranch::class;
    public static function getNavigationIcon(): string
    {
        return __('settingcompany::branch.resource.navigation_icon');
    }
    public static function getNavigationGroup(): ?string
    {
        return __('settingcompany::branch.resource.navigation_group');
    }
    public static function getNavigationLabel(): string
    {
        return __('settingcompany::branch.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('settingcompany::branch.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('settingcompany::branch.resource.plural_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return BranchForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return BranchTable::table($table);
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
            'index' => Pages\ListBranch::route('/'),
            'create' => Pages\CreateBranch::route('/create'),
            'edit' => Pages\EditBranch::route('/{record}/edit'),
        ];
    }
}

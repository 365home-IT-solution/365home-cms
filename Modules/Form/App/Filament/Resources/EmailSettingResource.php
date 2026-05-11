<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources;

use Modules\Form\App\Filament\Resources\EmailSettingResource\Forms\EmailSettingForm;
use Modules\Form\App\Filament\Resources\EmailSettingResource\Tables\EmailSettingTable;
use Modules\Form\App\Filament\Resources\EmailSettingResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Form\Entities\EmailSetting;

class EmailSettingResource extends Resource
{
    protected static ?string $model = EmailSetting::class;

    public static function getNavigationIcon(): string
    {
        return __('form::email-setting.resource.navigation_icon');
    }
    public static function getNavigationGroup(): ?string
    {
        return __('form::email-setting.resource.navigation_group');
    }
    public static function getNavigationLabel(): string
    {
        return __('form::email-setting.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('form::email-setting.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('form::email-setting.resource.plural_model_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return EmailSettingForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return EmailSettingTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmailSetting::route('/'),
            'create' => Pages\CreateEmailSetting::route('/create'),
            'edit' => Pages\EditEmailSetting::route('/{record}/edit'),
        ];
    }
}
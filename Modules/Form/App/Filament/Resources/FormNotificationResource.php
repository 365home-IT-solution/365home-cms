<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources;

use Modules\Form\App\Filament\Resources\FormNotificationResource\Forms\FormNotificationForm;
use Modules\Form\App\Filament\Resources\FormNotificationResource\Tables\FormNotificationTable;
use Modules\Form\App\Filament\Resources\FormNotificationResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Form\Entities\FormNotification;

class FormNotificationResource extends Resource
{
    protected static ?string $model = FormNotification::class;

    public static function getNavigationIcon(): string
    {
        return __('form::form-notification.resource.navigation_icon');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('form::form-notification.resource.navigation_group');
    }
    public static function getNavigationLabel(): string
    {
        return __('form::form-notification.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('form::form-notification.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('form::form-notification.resource.plural_model_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return FormNotificationForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return FormNotificationTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFormNotification::route('/'),
            'create' => Pages\CreateFormNotification::route('/create'),
            'edit' => Pages\EditFormNotification::route('/{record}/edit'),
        ];
    }
}
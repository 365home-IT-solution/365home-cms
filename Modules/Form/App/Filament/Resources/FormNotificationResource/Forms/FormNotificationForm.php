<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\FormNotificationResource\Forms;

use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Modules\Form\Entities\Form as EntitiesForm;

class FormNotificationForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                self::formSection(),
            ]);
    }

    private static function formSection(): Section
    {
        return Section::make()
            ->schema([
                self::formIdSelect(),
                self::messagesGrid(),
            ])
            ->columns(1);
    }

    private static function formIdSelect(): Select
    {
        return Select::make('form_id')
            ->label(__('form::form-notification.form.label.form_id'))
            ->options(EntitiesForm::pluck('name', 'id'))
            ->required();
    }

    private static function messagesGrid(): Grid
    {
        return Grid::make(2)
            ->schema([
                self::successMessageInput(),
                self::errorMessageInput(),
            ]);
    }

    private static function successMessageInput(): TextInput
    {
        return TextInput::make('success_message')
            ->label(__('form::form-notification.form.label.success_message'))
            ->default(__('form::form-notification.form.default.success_message'))
            ->required();
    }

    private static function errorMessageInput(): TextInput
    {
        return TextInput::make('error_message')
            ->label(__('form::form-notification.form.label.error_message'))
            ->default(__('form::form-notification.form.default.error_message'))
            ->required();
    }
}

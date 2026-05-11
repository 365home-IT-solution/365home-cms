<?php

declare(strict_types=1);

namespace Modules\Component\App\Filament\Resources\ComponentResource\Forms;

use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Modules\Component\App\Enums\FieldInputType;
use Modules\Component\App\Enums\FieldType;

class ComponentForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make()
                    ->schema([
                        Grid::make(1)
                            ->columnSpan(2)
                            ->schema([
                                self::componentFields(),
                                self::componentFieldsLabel(),
                                self::configRepeater(),
                            ]),
                    ]),
            ]);
    }

    protected static function componentFields(): TextInput
    {
        return TextInput::make('name')
            ->label(__('component::component.form.label.name'))
            ->placeholder(__('component::component.form.placeholder.name'))
            ->required()
            ->rules(['string', 'max:255']);
    }

    protected static function componentFieldsLabel(): TextInput
    {
        return TextInput::make('label')
            ->label('Tên hiển thị')
            ->placeholder(__('component::component.form.placeholder.label'))
            ->required()
            ->rules(['string', 'max:255']);
    }

    protected static function configRepeater(): Repeater
    {
        return Repeater::make('configurations')
            ->label(__('component::component.form.label.configurations'))
            ->relationship('configurations')
            ->schema([
                TextInput::make('name')
                    ->label(__('component::component.form.label.config_name'))
                    ->placeholder(__('component::component.form.placeholder.config_name'))
                    ->required()
                    ->rules(['string', 'max:255']),
                TextInput::make('label')
                    ->label(__('component::component.form.label.label'))
                    ->placeholder(__('component::component.form.placeholder.label'))
                    ->required(),
                Select::make('type')
                    ->label(__('component::component.form.label.type'))
                    ->options(self::getFieldTypeOptions())
                    ->required(),
                Select::make('type_field')
                    ->label(__('component::component.form.label.type_field'))
                    ->options(self::getFieldInputTypeOptions())
                    ->required(),
                TextInput::make('field_set')
                    ->label(__('component::component.form.label.field_set'))
                    ->placeholder(__('component::component.form.placeholder.field_set'))
                    ->required(),
                Toggle::make('has_options')
                    ->label(__('component::component.form.label.has_options'))
                    ->reactive()
                    ->helperText(__('component::component.form.helper_text.has_options')),
                Repeater::make('options')
                    ->label(__('component::component.form.label.options'))
                    ->relationship('options')
                    ->schema([
                        TextInput::make('option_label')
                            ->label(__('component::component.form.label.option_label')),
                        TextInput::make('option_value')
                            ->label(__('component::component.form.label.option_value')),
                    ])
                    ->columns(2)
                    ->visible(fn ($get) => $get('has_options'))
                    ->addActionLabel(__('component::component.form.action.add_option'))
                    ->columnSpan('full'),
            ])
            ->columns(2)
            ->reorderable();
    }

    protected static function getFieldTypeOptions(): array
    {
        return collect(FieldType::cases())->mapWithKeys(function ($case) {
            return [$case->value => $case->name];
        })->toArray();
    }

    protected static function getFieldInputTypeOptions(): array
    {
        return collect(FieldInputType::cases())->mapWithKeys(function ($case) {
            return [$case->value => $case->name];
        })->toArray();
    }
}
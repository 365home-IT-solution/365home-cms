<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\FormResource\Forms;

use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\Str;

class FormForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make()
                    ->heading('')
                    ->schema([
                        self::nameField(),
                        self::formFieldsSection(),
                    ])
                    ->columns(1)
            ]);
    }

    private static function nameField(): TextInput
    {
        return TextInput::make('name')
            ->label(__('form::form.form.label.name'))
            ->placeholder(__('form::form.form.placeholder.name'))
            ->required()
            ->maxLength(255);
    }

    private static function formFieldsSection(): Section
    {
        return Section::make(__('form::form.form.label.formFields'))
            ->schema([
                self::formFieldsRepeater(),
                self::submitButtonSection(),
            ])
            ->collapsible();
    }

    private static function formFieldsRepeater(): Repeater
    {
        return Repeater::make('formFields')
            ->label('')
            ->relationship('formFields')
            ->schema([
                self::formFieldGrid(),
                self::optionsTextarea(),
                self::validationGrid(),
            ])
            ->columns(2)
            ->addable(true)
            ->deletable(true)
            ->reorderable(true)
            ->collapsible()
            ->orderColumn('sort_order')
            ->addActionLabel(__('form::form.form.actions.add_field'))
            ->itemLabel(fn(array $state): ?string => $state['label'] ?? null);
    }

    private static function formFieldGrid(): Grid
    {
        return Grid::make(3)
            ->schema([
                self::typeSelect(),
                self::labelInput(),
                self::nameInput(),
            ]);
    }

    private static function typeSelect(): Select
    {
        return Select::make('type')
            ->label(__('form::form.form.label.type'))
            ->options(__('form::form.form.options'))
            ->required()
            ->searchable();
    }

    private static function labelInput(): TextInput
    {
        return TextInput::make('label')
            ->label(__('form::form.form.label.label'))
            ->required()
            ->placeholder(__('form::form.form.placeholder.label'))
            ->live(onBlur: true)
            ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) {
                if (($get('slug') ?? '') !== Str::slug($old)) {
                    return;
                }
                $set('name', Str::slug($state));
            });
    }

    private static function nameInput(): TextInput
    {
        return TextInput::make('name')
            ->label(__('form::form.form.label.slug'))
            ->required()
            ->placeholder(__('form::form.form.placeholder.slug'));
    }

    private static function optionsTextarea(): Textarea
    {
        return Textarea::make('options')
            ->label(__('form::form.form.label.options'))
            ->helperText(__('form::form.form.helperText.options'))
            ->nullable()
            ->columnSpanFull()
            ->placeholder(__('form::form.form.placeholder.options'))
            ->visible(fn($get) => in_array($get('type'), ['select', 'radio']));
    }

    private static function validationGrid(): Grid
    {
        return Grid::make(12)
            ->schema([
                self::isRequiredToggle(),
                self::minLengthInput(),
                self::maxLengthInput(),
            ]);
    }

    private static function isRequiredToggle(): Toggle
    {
        return Toggle::make('is_required')
            ->label(__('form::form.form.label.is_required'))
            ->default(false)
            ->onColor('success')
            ->columnSpan(4)
            ->offColor('danger');
    }

    private static function minLengthInput(): TextInput
    {
        return TextInput::make('min_length')
            ->label(__('form::form.form.label.min_length'))
            ->numeric()
            ->nullable()
            ->helperText(__('form::form.form.helperText.min_length'))
            ->columnSpan(4)
            ->visible(fn($get) => in_array($get('type'), ['text', 'textarea']));
    }

    private static function maxLengthInput(): TextInput
    {
        return TextInput::make('max_length')
            ->label(__('form::form.form.label.max_length'))
            ->numeric()
            ->nullable()
            ->helperText(__('form::form.form.helperText.max_length'))
            ->columnSpan(4)
            ->visible(fn($get) => in_array($get('type'), ['text', 'textarea']));
    }

    private static function submitButtonSection(): Section
    {
        return Section::make([
            TextInput::make('submit_button_text')
                ->label(__('form::form.form.label.submit_button_text'))
                ->default('Gửi')
                ->required()
                ->maxLength(255),
        ]);
    }
}

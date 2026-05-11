<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\FormSubmissionResource\Forms;

use Carbon\Carbon;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Modules\Form\Entities\FormSubmission;

class FormSubmissionForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                self::formFieldValuesSection(),
                self::viewedToggleSection(),
            ]);
    }

    private static function formFieldValuesSection(): Section
    {
        return Section::make()
            ->schema([
                KeyValue::make('formFieldValues')
                    ->label(__('form::form-submission.form.label.form_field_values'))
                    ->addActionLabel(__('form::form-submission.form.key_value.add_action_label'))
                    ->keyLabel(__('form::form-submission.form.key_value.key_label'))
                    ->valueLabel(__('form::form-submission.form.key_value.value_label'))
                    ->disableAddingRows()
                    ->disableDeletingRows()
                    ->disableEditingKeys()
                    ->columnSpanFull()
                    ->formatStateUsing(fn(FormSubmission $record) => $record->formFieldValues->mapWithKeys(
                        fn($fieldValue) => [
                            $fieldValue->field->label ?? $fieldValue->field->name ?? 'Thông tin bổ sung' => $fieldValue->value
                        ]
                    )->toArray())
            ]);
    }

    private static function viewedToggleSection(): Section
    {
        return Section::make()
            ->schema([
                Grid::make(2)
                    ->schema([
                        Toggle::make('is_viewed')
                            ->label(__('form::form-submission.form.label.is_viewed'))
                            ->onIcon('heroicon-m-eye')
                            ->offIcon('heroicon-m-eye-slash')
                            ->default(false)
                            ->reactive()
                            ->helperText(__('form::form-submission.form.helper_text.is_viewed'))
                            ->afterStateUpdated(function ($state, callable $set) {
                                $set('viewed_by', $state ? auth()->id() : null);
                                $set('viewed_at', $state ? Carbon::now() : null);
                            })
                    ])
            ]);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\EmailSettingResource\Forms;

use Filament\Forms\Components\Grid;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Modules\Form\Entities\Form as EntitiesForm;

class EmailSettingForm
{
    private const RICH_EDITOR_BUTTONS = [
        'bold', 'italic', 'underline', 'strike', 'link',
        'orderedList', 'unorderedList', 'h2', 'h3', 'paragraph',
        'undo', 'redo', 'attachFiles', 'imageResize',
    ];

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make()
                    ->schema([
                        self::formAndEmailFields(),
                        self::subjectAndHeadersFields(),
                        self::messageBodyField(),
                    ])
                    ->columns(1)
            ]);
    }

    private static function formAndEmailFields(): Grid
    {
        return Grid::make(3)
            ->schema([
                self::formIdField(),
                self::toEmailField(),
                self::fromEmailField(),
            ]);
    }

    private static function subjectAndHeadersFields(): Grid
    {
        return Grid::make(1)
            ->schema([
                self::subjectField(),
            ]);
    }

    private static function formIdField(): Select
    {
        return Select::make('form_id')
            ->label(__('form::email-setting.form.label.form_id'))
            ->options(fn () => EntitiesForm::pluck('name', 'id'))
            ->required();
    }

    private static function toEmailField(): TextInput
    {
        return TextInput::make('to_email')
            ->label(__('form::email-setting.form.label.to_email'))
            ->placeholder(__('form::email-setting.form.placeholder.to_email'))
            ->email()
            ->required();   
    }

    private static function fromEmailField(): TextInput
    {
        return TextInput::make('from_email')
            ->label(__('form::email-setting.form.label.from_email'))
            ->placeholder(__('form::email-setting.form.placeholder.from_email'))
            ->email()
            ->required();
    }

    private static function subjectField(): TextInput
    {
        return TextInput::make('subject')
            ->label(__('form::email-setting.form.label.subject'))
            ->placeholder(__('form::email-setting.form.placeholder.subject'))
            ->required();
    }

    private static function messageBodyField(): RichEditor
    {
        return RichEditor::make('message_body')
            ->label(__('form::email-setting.form.label.message_body'))
            ->required()
            ->toolbarButtons(self::RICH_EDITOR_BUTTONS)
            ->fileAttachmentsDisk('public')
            ->fileAttachmentsDirectory('email-attachments')
            ->fileAttachmentsVisibility('public')
            ->helperText(__('form::email-setting.form.helper_text.message_body'))
            ->columnSpanFull();
    }
}

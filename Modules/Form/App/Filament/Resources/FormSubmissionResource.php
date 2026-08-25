<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources;

use Modules\Form\App\Filament\Resources\FormSubmissionResource\Forms\FormSubmissionForm;
use Modules\Form\App\Filament\Resources\FormSubmissionResource\Tables\FormSubmissionTable;
use Modules\Form\App\Filament\Resources\FormSubmissionResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Form\Entities\FormSubmission;

class FormSubmissionResource extends Resource
{
    protected static ?string $model = FormSubmission::class;

    // Nhóm "Biểu mẫu" đang ẩn tạm khỏi menu — bật lại bằng cách xoá method này.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationIcon(): string
    {
        return __('form::form-submission.resource.navigation_icon');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('form::form-submission.resource.navigation_group');
    }
    public static function getNavigationLabel(): string
    {
        return __('form::form-submission.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('form::form-submission.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('form::form-submission.resource.plural_model_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return FormSubmissionForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return FormSubmissionTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFormSubmission::route('/'),
            'create' => Pages\CreateFormSubmission::route('/create'),
            'edit' => Pages\EditFormSubmission::route('/{record}/edit'),
        ];
    }
}

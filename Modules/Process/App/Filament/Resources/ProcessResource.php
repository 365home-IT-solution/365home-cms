<?php

declare(strict_types=1);

namespace Modules\Process\App\Filament\Resources;

use Modules\Process\App\Filament\Resources\ProcessResource\Forms\ProcessForm;
use Modules\Process\App\Filament\Resources\ProcessResource\Tables\ProcessTable;
use Modules\Process\Entities\Process;
use Modules\Process\App\Filament\Resources\ProcessResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class ProcessResource extends Resource
{
    protected static ?string $model = Process::class;

    // Nhóm "Nội dung" đang ẩn tạm khỏi menu — bật lại bằng cách xoá method này.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationIcon(): string
    {
        return __('process::process.resource.navigation_icon');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('process::process.resource.navigation_group');
    }
    
    public static function getNavigationLabel(): string
    {
        return __('process::process.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('process::process.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('process::process.resource.plural_model_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return ProcessForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return ProcessTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProcess::route('/'),
            'create' => Pages\CreateProcess::route('/create'),
            'edit' => Pages\EditProcess::route('/{record}/edit'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\AppPage\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\AppPage\App\Filament\Resources\AppPageResource\Forms\AppPageForm;
use Modules\AppPage\App\Filament\Resources\AppPageResource\Pages;
use Modules\AppPage\App\Filament\Resources\AppPageResource\Tables\AppPageTable;
use Modules\AppPage\App\Models\AppPage;

class AppPageResource extends Resource
{
    protected static ?string $model = AppPage::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-device-phone-mobile';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý API';
    }

    public static function getNavigationLabel(): string
    {
        return 'Trang App';
    }

    public static function getModelLabel(): string
    {
        return 'Trang App';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Trang App';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return AppPageForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return AppPageTable::table($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAppPage::route('/'),
            'create' => Pages\CreateAppPage::route('/create'),
            'edit'   => Pages\EditAppPage::route('/{record}/edit'),
        ];
    }
}

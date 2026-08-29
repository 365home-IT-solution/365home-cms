<?php

declare(strict_types=1);

namespace Modules\Themestudio\App\Filament\Resources;

use Modules\ThemeStudio\App\Filament\Resources\ThemeStudioResource\Forms\ThemeStudioForm;
use Modules\ThemeStudio\App\Filament\Resources\ThemeStudioResource\Tables\ThemeStudioTable;
use Modules\ThemeStudio\App\Filament\Resources\ThemeStudioResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\ThemeSetting\App\Models\Theme;

class ThemeStudioResource extends Resource
{
    protected static ?string $model = Theme::class;

    protected static ?string $navigationIcon = 'heroicon-o-code-bracket';

    protected static ?string $navigationGroup = 'Cấu hình web';

    public static function getNavigationLabel(): string
    {
        return 'Theme Studio';
    }

    public static function getModelLabel(): string
    {
        return 'Theme';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Theme';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    // Ẩn hẳn khỏi admin — cùng lý do ThemeResource (Modules/ThemeSetting): dùng chung 1 bảng dữ
    // liệu, KHÔNG được nối vào luồng render web thật, chỉ là tính năng dở dang.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return ThemeStudioForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return ThemeStudioTable::table($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListThemeStudio::route('/'),
            'create' => Pages\CreateThemeStudio::route('/create'),
            'edit' => Pages\EditThemeStudio::route('/{record}/edit'),
        ];
    }
}

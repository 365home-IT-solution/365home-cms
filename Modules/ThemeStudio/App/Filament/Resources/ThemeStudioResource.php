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

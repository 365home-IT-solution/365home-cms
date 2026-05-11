<?php

namespace Modules\ThemeStudio\App\Filament\Resources\ThemeStudioResource\Forms\Tabs;

use Filament\Forms\Components\Tabs\Tab;
use Modules\ThemeStudio\App\Filament\Resources\ThemeStudioResource\Forms\Sections;

class ThemeSectionTab
{
    public static function make(): Tab
    {
        return Tab::make('Sections')
            ->icon('heroicon-m-squares-2x2')
            ->schema([
                Sections\SectionForm::make(),
            ]);
    }
}
<?php

namespace Modules\Themestudio\App\Filament\Resources\ThemeStudioResource\Forms;

use Filament\Forms\Components\Tabs;
use Filament\Forms\Form;
use Modules\ThemeStudio\App\Filament\Resources\ThemeStudioResource\Forms\Tabs\ThemeInfoTab;
use Modules\ThemeStudio\App\Filament\Resources\ThemeStudioResource\Forms\Tabs\ThemeSectionTab;

class ThemeStudioForm
{
    public static $theme;

    public static function form(Form $form): Form
    {
        self::$theme = $form->getRecord();

        return $form->schema([
            Tabs::make('Theme Studio')
                ->tabs([
                    ThemeInfoTab::make(),
                    ThemeSectionTab::make()
                ])
                ->columnSpanFull()
        ]);
    }
}

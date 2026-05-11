<?php

namespace Modules\ThemeStudio\App\Filament\Resources\ThemeStudioResource\Forms\Tabs;

use Filament\Forms\Components\Tabs\Tab;
use Modules\ThemeStudio\App\Filament\Resources\ThemeStudioResource\Forms\Sections;
use Rupadana\FilamentSlider\Components\Concerns\InputSliderBehaviour;
use Rupadana\FilamentSlider\Components\InputSlider;
use Rupadana\FilamentSlider\Components\InputSliderGroup;

class ThemeInfoTab
{
    public static function make(): Tab
    {
        return Tab::make('Thông tin')
            ->icon('heroicon-m-information-circle')
            ->schema([
                Sections\BasicInfoSection::make(),
                Sections\DetailSection::make(),
                Sections\PreviewSection::make(),
            ]);
    }
}

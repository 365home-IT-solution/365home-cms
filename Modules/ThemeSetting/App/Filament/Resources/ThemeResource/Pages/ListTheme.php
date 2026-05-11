<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\Pages;

use Modules\ThemeSetting\App\Filament\Resources\ThemeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTheme extends ListRecords
{
    protected static string $resource = ThemeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

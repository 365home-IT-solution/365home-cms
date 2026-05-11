<?php

declare(strict_types=1);

namespace Modules\Themestudio\App\Filament\Resources\ThemeStudioResource\Pages;

use Modules\Themestudio\App\Filament\Resources\ThemeStudioResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListThemeStudio extends ListRecords
{
    protected static string $resource = ThemeStudioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

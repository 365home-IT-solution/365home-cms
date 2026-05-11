<?php

declare(strict_types=1);

namespace Modules\Themestudio\App\Filament\Resources\ThemeStudioResource\Pages;

use Modules\Themestudio\App\Filament\Resources\ThemeStudioResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditThemeStudio extends EditRecord
{
    protected static string $resource = ThemeStudioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
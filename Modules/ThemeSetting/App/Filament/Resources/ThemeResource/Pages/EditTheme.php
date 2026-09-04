<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\Pages;

use Modules\ThemeSetting\App\Filament\Resources\ThemeResource;
use Filament\Actions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\EditRecord;

class EditTheme extends EditRecord
{
    protected static string $resource = ThemeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    public function getBreadcrumbs(): array
    {
        return [
            url('/admin/themes') => 'Theme',
            '' => 'Cấu hình theme',
        ];
    }

    public function getTitle(): string
    {
        $themeName = $this->record->name;
        return 'Cấu hình ' . $themeName;
    }
}

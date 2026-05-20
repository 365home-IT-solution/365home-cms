<?php

declare(strict_types=1);

namespace Modules\AppPage\App\Filament\Resources\AppPageResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\AppPage\App\Filament\Resources\AppPageResource;

class EditAppPage extends EditRecord
{
    protected static string $resource = AppPageResource::class;

    protected static string $view = 'filament.resources.app-page.pages.edit';

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function afterSave(): void
    {
        $this->dispatch('app-page-json-refresh');
    }
}

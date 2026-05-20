<?php

declare(strict_types=1);

namespace Modules\AppPage\App\Filament\Resources\AppPageResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\AppPage\App\Filament\Resources\AppPageResource;

class ListAppPage extends ListRecords
{
    protected static string $resource = AppPageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

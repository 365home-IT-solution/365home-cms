<?php

declare(strict_types=1);

namespace Modules\Page\App\Filament\Resources\PageResource\Pages;

use Modules\Page\App\Filament\Resources\PageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPage extends ListRecords
{
    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

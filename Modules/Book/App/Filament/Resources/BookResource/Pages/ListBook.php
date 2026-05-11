<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources\BookResource\Pages;

use Modules\Book\App\Filament\Resources\BookResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBook extends ListRecords
{
    protected static string $resource = BookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

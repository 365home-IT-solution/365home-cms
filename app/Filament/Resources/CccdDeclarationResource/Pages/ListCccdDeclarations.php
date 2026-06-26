<?php

declare(strict_types=1);

namespace App\Filament\Resources\CccdDeclarationResource\Pages;

use App\Filament\Resources\CccdDeclarationResource;
use Filament\Resources\Pages\ListRecords;

class ListCccdDeclarations extends ListRecords
{
    protected static string $resource = CccdDeclarationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

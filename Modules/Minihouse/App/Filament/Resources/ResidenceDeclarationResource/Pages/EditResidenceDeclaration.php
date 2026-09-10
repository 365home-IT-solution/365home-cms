<?php

namespace Modules\Minihouse\App\Filament\Resources\ResidenceDeclarationResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\ResidenceDeclarationResource;

class EditResidenceDeclaration extends EditRecord
{
    protected static string $resource = ResidenceDeclarationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

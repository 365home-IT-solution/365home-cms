<?php

declare(strict_types=1);

namespace App\Filament\Resources\CccdDeclarationResource\Pages;

use App\Filament\Resources\CccdDeclarationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCccdDeclaration extends EditRecord
{
    protected static string $resource = CccdDeclarationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\TtlockAccountResource\Pages;

use App\Filament\Resources\TtlockAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTtlockAccount extends EditRecord
{
    protected static string $resource = TtlockAccountResource::class;

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

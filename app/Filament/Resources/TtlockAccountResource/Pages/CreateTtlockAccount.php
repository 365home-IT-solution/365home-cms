<?php

declare(strict_types=1);

namespace App\Filament\Resources\TtlockAccountResource\Pages;

use App\Filament\Resources\TtlockAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTtlockAccount extends CreateRecord
{
    protected static string $resource = TtlockAccountResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

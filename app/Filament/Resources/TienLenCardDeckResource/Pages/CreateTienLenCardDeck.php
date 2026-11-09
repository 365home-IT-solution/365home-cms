<?php

declare(strict_types=1);

namespace App\Filament\Resources\TienLenCardDeckResource\Pages;

use App\Filament\Resources\TienLenCardDeckResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTienLenCardDeck extends CreateRecord
{
    protected static string $resource = TienLenCardDeckResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return TienLenCardDeckResource::saveFromForm(null, $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}

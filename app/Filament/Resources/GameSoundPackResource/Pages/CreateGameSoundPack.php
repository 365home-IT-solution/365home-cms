<?php

declare(strict_types=1);

namespace App\Filament\Resources\GameSoundPackResource\Pages;

use App\Filament\Resources\GameSoundPackResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateGameSoundPack extends CreateRecord
{
    protected static string $resource = GameSoundPackResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return GameSoundPackResource::saveFromForm(null, $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}

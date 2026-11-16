<?php

declare(strict_types=1);

namespace App\Filament\Resources\GameStickerPackResource\Pages;

use App\Filament\Resources\GameStickerPackResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateGameStickerPack extends CreateRecord
{
    protected static string $resource = GameStickerPackResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return GameStickerPackResource::saveFromForm(null, $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}

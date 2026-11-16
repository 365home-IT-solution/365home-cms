<?php

declare(strict_types=1);

namespace App\Filament\Resources\GameStickerPackResource\Pages;

use App\Filament\Resources\GameStickerPackResource;
use App\Models\GameStickerPack;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditGameStickerPack extends EditRecord
{
    protected static string $resource = GameStickerPackResource::class;

    /** @param GameStickerPack $record */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $pack = GameStickerPackResource::saveFromForm($record, $data);
        $this->form->fill(['icon' => null] + $pack->only(['name', 'key', 'games', 'sort_order', 'is_active']));

        return $pack;
    }
}

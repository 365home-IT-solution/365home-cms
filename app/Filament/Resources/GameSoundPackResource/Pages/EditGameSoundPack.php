<?php

declare(strict_types=1);

namespace App\Filament\Resources\GameSoundPackResource\Pages;

use App\Filament\Resources\GameSoundPackResource;
use App\Models\GameSoundPack;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditGameSoundPack extends EditRecord
{
    protected static string $resource = GameSoundPackResource::class;

    // Cột sounds (đường dẫn file) trùng tên với ô upload — không đổ vào form
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ['sounds' => []] + $data;
    }

    /** @param GameSoundPack $record */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $pack = GameSoundPackResource::saveFromForm($record, $data);
        // Xoá file vừa chọn khỏi ô upload, "Âm thanh hiện tại" hiện file mới
        $this->form->fill(['sounds' => [],'cover' => null, 'remove_sounds' => []]
            + $pack->only(['game', 'name', 'key', 'sort_order', 'is_active']));

        return $pack;
    }
}

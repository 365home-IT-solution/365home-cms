<?php

declare(strict_types=1);

namespace App\Filament\Resources\TienLenCardDeckResource\Pages;

use App\Filament\Resources\TienLenCardDeckResource;
use App\Models\TienLenCardDeck;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTienLenCardDeck extends EditRecord
{
    protected static string $resource = TienLenCardDeckResource::class;

    /** @param TienLenCardDeck $record */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $deck = TienLenCardDeckResource::saveFromForm($record, $data);
        // Xoá file vừa chọn khỏi ô upload, lưới "Ảnh hiện tại" hiện ảnh mới
        $this->form->fill(['back' => null, 'preview' => null, 'cards' => [], 'cards_zip' => null] + $deck->only(['name', 'key', 'sort_order', 'is_active']));

        return $deck;
    }
}

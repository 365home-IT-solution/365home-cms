<?php

declare(strict_types=1);

namespace App\Filament\Resources\GameStickerPackResource\Pages;

use App\Filament\Resources\GameStickerPackResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGameStickerPacks extends ListRecords
{
    protected static string $resource = GameStickerPackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Thêm bộ sticker'),
        ];
    }
}

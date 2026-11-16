<?php

declare(strict_types=1);

namespace App\Filament\Resources\GameSoundPackResource\Pages;

use App\Filament\Resources\GameSoundPackResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGameSoundPacks extends ListRecords
{
    protected static string $resource = GameSoundPackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Thêm bộ âm thanh'),
        ];
    }
}

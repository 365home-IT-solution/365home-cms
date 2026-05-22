<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomImageResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Product\App\Filament\Resources\RoomImageResource;

class ListRoomImage extends ListRecords
{
    protected static string $resource = RoomImageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

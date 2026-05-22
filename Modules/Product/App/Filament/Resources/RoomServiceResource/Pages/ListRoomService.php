<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomServiceResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Product\App\Filament\Resources\RoomServiceResource;

class ListRoomService extends ListRecords
{
    protected static string $resource = RoomServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

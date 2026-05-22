<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomAmenityResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Product\App\Filament\Resources\RoomAmenityResource;

class ListRoomAmenity extends ListRecords
{
    protected static string $resource = RoomAmenityResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

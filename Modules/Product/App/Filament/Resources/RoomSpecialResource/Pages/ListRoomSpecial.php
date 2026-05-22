<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomSpecialResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Product\App\Filament\Resources\RoomSpecialResource;

class ListRoomSpecial extends ListRecords
{
    protected static string $resource = RoomSpecialResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

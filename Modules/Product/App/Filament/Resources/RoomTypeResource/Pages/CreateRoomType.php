<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomTypeResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Product\App\Filament\Resources\RoomTypeResource;

class CreateRoomType extends CreateRecord
{
    protected static string $resource = RoomTypeResource::class;
}

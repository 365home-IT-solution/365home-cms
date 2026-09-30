<?php

namespace Modules\Minihouse\App\Filament\Resources\RoomReviewResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Minihouse\App\Filament\Resources\RoomReviewResource;

class ListRoomReviews extends ListRecords
{
    protected static string $resource = RoomReviewResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }
}

<?php

namespace Modules\Minihouse\App\Filament\Resources\AnnouncementResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Minihouse\App\Filament\Resources\AnnouncementResource;

class CreateAnnouncement extends CreateRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}

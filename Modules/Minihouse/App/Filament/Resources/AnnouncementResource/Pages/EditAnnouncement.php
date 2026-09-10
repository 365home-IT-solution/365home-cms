<?php

namespace Modules\Minihouse\App\Filament\Resources\AnnouncementResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\AnnouncementResource;

class EditAnnouncement extends EditRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

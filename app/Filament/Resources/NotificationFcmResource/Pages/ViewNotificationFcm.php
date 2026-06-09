<?php

namespace App\Filament\Resources\NotificationFcmResource\Pages;

use App\Filament\Resources\NotificationFcmResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewNotificationFcm extends ViewRecord
{
    protected static string $resource = NotificationFcmResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

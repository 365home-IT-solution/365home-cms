<?php

namespace App\Filament\Resources\NotificationFcmResource\Pages;

use App\Filament\Resources\NotificationFcmResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNotificationFcm extends ListRecords
{
    protected static string $resource = NotificationFcmResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Gửi thông báo mới'),
        ];
    }
}

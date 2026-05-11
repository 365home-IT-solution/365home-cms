<?php

declare(strict_types=1);

namespace Modules\Zns\App\Filament\Resources\ZnsNotificationResource\Pages;

use Modules\Zns\App\Filament\Resources\ZnsNotificationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditZnsNotification extends EditRecord
{
    protected static string $resource = ZnsNotificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
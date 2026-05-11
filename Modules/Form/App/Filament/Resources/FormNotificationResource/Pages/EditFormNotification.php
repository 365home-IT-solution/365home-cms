<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\FormNotificationResource\Pages;

use Modules\Form\App\Filament\Resources\FormNotificationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFormNotification extends EditRecord
{
    protected static string $resource = FormNotificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
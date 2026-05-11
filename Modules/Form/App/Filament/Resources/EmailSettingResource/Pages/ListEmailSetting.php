<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\EmailSettingResource\Pages;

use Modules\Form\App\Filament\Resources\EmailSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEmailSetting extends ListRecords
{
    protected static string $resource = EmailSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

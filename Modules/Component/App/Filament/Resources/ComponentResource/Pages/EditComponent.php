<?php

declare(strict_types=1);

namespace Modules\Component\App\Filament\Resources\ComponentResource\Pages;

use Modules\Component\App\Filament\Resources\ComponentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditComponent extends EditRecord
{
    protected static string $resource = ComponentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

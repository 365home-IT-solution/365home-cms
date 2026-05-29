<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ManualLockPasswordResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Product\App\Filament\Resources\ManualLockPasswordResource;

class ListManualLockPasswords extends ListRecords
{
    protected static string $resource = ManualLockPasswordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

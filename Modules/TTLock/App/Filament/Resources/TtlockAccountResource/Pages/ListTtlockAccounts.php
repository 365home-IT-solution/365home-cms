<?php

declare(strict_types=1);

namespace Modules\TTLock\App\Filament\Resources\TtlockAccountResource\Pages;

use Modules\TTLock\App\Filament\Resources\TtlockAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTtlockAccounts extends ListRecords
{
    protected static string $resource = TtlockAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

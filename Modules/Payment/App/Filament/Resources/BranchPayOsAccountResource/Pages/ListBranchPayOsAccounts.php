<?php

namespace Modules\Payment\App\Filament\Resources\BranchPayOsAccountResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Payment\App\Filament\Resources\BranchPayOsAccountResource;

class ListBranchPayOsAccounts extends ListRecords
{
    protected static string $resource = BranchPayOsAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

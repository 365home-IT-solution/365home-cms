<?php

namespace Modules\Payment\App\Filament\Resources\PartnerSePayAccountResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Payment\App\Filament\Resources\PartnerSePayAccountResource;

class ListPartnerSePayAccounts extends ListRecords
{
    protected static string $resource = PartnerSePayAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}

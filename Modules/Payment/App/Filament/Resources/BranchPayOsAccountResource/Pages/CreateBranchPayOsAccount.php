<?php

namespace Modules\Payment\App\Filament\Resources\BranchPayOsAccountResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Payment\App\Filament\Resources\BranchPayOsAccountResource;

class CreateBranchPayOsAccount extends CreateRecord
{
    protected static string $resource = BranchPayOsAccountResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

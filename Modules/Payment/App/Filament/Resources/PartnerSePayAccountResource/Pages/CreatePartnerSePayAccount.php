<?php

namespace Modules\Payment\App\Filament\Resources\PartnerSePayAccountResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Payment\App\Filament\Resources\PartnerSePayAccountResource;

class CreatePartnerSePayAccount extends CreateRecord
{
    protected static string $resource = PartnerSePayAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return PartnerSePayAccountResource::normalize($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

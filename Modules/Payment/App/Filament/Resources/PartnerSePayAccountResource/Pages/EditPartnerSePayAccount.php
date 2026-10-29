<?php

namespace Modules\Payment\App\Filament\Resources\PartnerSePayAccountResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\Payment\App\Filament\Resources\PartnerSePayAccountResource;

class EditPartnerSePayAccount extends EditRecord
{
    protected static string $resource = PartnerSePayAccountResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return PartnerSePayAccountResource::normalize($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\CollectionCampaignResource\Pages;

use App\Filament\Resources\CollectionCampaignResource;
use Filament\Resources\Pages\EditRecord;

class EditCollectionCampaign extends EditRecord
{
    protected static string $resource = CollectionCampaignResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        CollectionCampaignResource::guardUpdate($this->getRecord(), $data);

        return $data;
    }
}

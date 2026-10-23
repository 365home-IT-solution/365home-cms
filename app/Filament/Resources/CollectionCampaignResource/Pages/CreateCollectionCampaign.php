<?php

declare(strict_types=1);

namespace App\Filament\Resources\CollectionCampaignResource\Pages;

use App\Filament\Resources\CollectionCampaignResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCollectionCampaign extends CreateRecord
{
    protected static string $resource = CollectionCampaignResource::class;

    // Tạo xong vào thẳng màn sửa để thêm chi nhánh / ảnh mảnh
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}

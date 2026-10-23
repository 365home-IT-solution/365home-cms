<?php

declare(strict_types=1);

namespace App\Filament\Resources\CollectionCampaignResource\Pages;

use App\Filament\Resources\CollectionCampaignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCollectionCampaigns extends ListRecords
{
    protected static string $resource = CollectionCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Thêm chiến dịch')];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScratchCardPrizeResource\Pages;

use App\Filament\Resources\ScratchCardPrizeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateScratchCardPrize extends CreateRecord
{
    protected static string $resource = ScratchCardPrizeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ScratchCardPrizeResource::normalize($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

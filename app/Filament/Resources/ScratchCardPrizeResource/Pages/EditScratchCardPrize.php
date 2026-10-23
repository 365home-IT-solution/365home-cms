<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScratchCardPrizeResource\Pages;

use App\Filament\Resources\ScratchCardPrizeResource;
use Filament\Resources\Pages\EditRecord;

class EditScratchCardPrize extends EditRecord
{
    protected static string $resource = ScratchCardPrizeResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ScratchCardPrizeResource::normalize($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

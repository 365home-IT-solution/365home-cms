<?php

namespace Modules\Product\App\Filament\Resources\PriceTemplateResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\Product\App\Filament\Resources\PriceTemplateResource;

class EditPriceTemplate extends EditRecord
{
    protected static string $resource = PriceTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

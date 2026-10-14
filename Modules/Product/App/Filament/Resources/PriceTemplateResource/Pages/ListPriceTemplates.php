<?php

namespace Modules\Product\App\Filament\Resources\PriceTemplateResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Product\App\Filament\Resources\PriceTemplateResource;

class ListPriceTemplates extends ListRecords
{
    protected static string $resource = PriceTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Tạo bảng giá mẫu'),
        ];
    }
}

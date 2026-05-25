<?php

namespace Modules\Product\App\Filament\Resources\AdditionServiceResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Product\App\Filament\Resources\AdditionServiceResource;

class ListAdditionServices extends ListRecords
{
    protected static string $resource = AdditionServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

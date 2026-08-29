<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources\PriceBoardResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Book\App\Filament\Resources\PriceBoardResource;

class ListPriceBoards extends ListRecords
{
    protected static string $resource = PriceBoardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

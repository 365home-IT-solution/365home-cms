<?php

declare(strict_types=1);

namespace Modules\Process\App\Filament\Resources\ProcessResource\Pages;

use Modules\Process\App\Filament\Resources\ProcessResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProcess extends ListRecords
{
    protected static string $resource = ProcessResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

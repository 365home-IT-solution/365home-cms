<?php

declare(strict_types=1);

namespace App\Filament\Resources\MiniGameResource\Pages;

use App\Filament\Resources\MiniGameResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMiniGames extends ListRecords
{
    protected static string $resource = MiniGameResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

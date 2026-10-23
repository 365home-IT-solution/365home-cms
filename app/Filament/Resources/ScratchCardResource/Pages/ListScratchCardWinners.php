<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScratchCardResource\Pages;

use App\Filament\Resources\ScratchCardResource;
use Filament\Resources\Pages\ListRecords;

class ListScratchCardWinners extends ListRecords
{
    protected static string $resource = ScratchCardResource::class;
}

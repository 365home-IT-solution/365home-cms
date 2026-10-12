<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppErrorResource\Pages;

use App\Filament\Resources\AppErrorResource;
use Filament\Resources\Pages\ListRecords;

class ListAppErrors extends ListRecords
{
    protected static string $resource = AppErrorResource::class;
}

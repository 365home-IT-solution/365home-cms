<?php

declare(strict_types=1);

namespace Modules\Motrak\App\Filament\Resources\MotrakSyncLogResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Motrak\App\Filament\Resources\MotrakSyncLogResource;

class ListMotrakSyncLogs extends ListRecords
{
    protected static string $resource = MotrakSyncLogResource::class;
}

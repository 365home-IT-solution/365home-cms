<?php

declare(strict_types=1);

namespace Modules\Process\App\Filament\Resources\ProcessResource\Pages;

use Modules\Process\App\Filament\Resources\ProcessResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProcess extends CreateRecord
{
    protected static string $resource = ProcessResource::class;
}
<?php

declare(strict_types=1);

namespace Modules\Component\App\Filament\Resources\ComponentResource\Pages;

use Modules\Component\App\Filament\Resources\ComponentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateComponent extends CreateRecord
{
    protected static string $resource = ComponentResource::class;
}

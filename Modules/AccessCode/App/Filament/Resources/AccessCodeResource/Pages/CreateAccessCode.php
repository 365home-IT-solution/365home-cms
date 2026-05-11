<?php

declare(strict_types=1);

namespace Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Pages;

use Modules\AccessCode\App\Filament\Resources\AccessCodeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAccessCode extends CreateRecord
{
    protected static string $resource = AccessCodeResource::class;
}
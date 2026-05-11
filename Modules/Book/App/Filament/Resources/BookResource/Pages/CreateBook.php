<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources\BookResource\Pages;

use Modules\Book\App\Filament\Resources\BookResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBook extends CreateRecord
{
    protected static string $resource = BookResource::class;
}
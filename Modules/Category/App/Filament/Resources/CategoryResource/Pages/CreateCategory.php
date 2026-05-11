<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources\CategoryResource\Pages;

use Modules\Category\App\Filament\Resources\CategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCategory extends CreateRecord
{   
    protected static string $resource = CategoryResource::class;
}
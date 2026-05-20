<?php

declare(strict_types=1);

namespace Modules\AppPage\App\Filament\Resources\AppPageResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\AppPage\App\Filament\Resources\AppPageResource;

class CreateAppPage extends CreateRecord
{
    protected static string $resource = AppPageResource::class;
}

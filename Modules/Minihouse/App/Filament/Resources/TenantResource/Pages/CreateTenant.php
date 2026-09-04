<?php

namespace Modules\Minihouse\App\Filament\Resources\TenantResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Minihouse\App\Filament\Resources\TenantResource;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;
}

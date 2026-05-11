<?php

declare(strict_types=1);

namespace Modules\DataPermission\App\Filament\Resources\DataScopeResource\Pages;

use Modules\DataPermission\App\Filament\Resources\DataScopeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDataScope extends CreateRecord
{
    protected static string $resource = DataScopeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

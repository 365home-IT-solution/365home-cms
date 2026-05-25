<?php

declare(strict_types=1);

namespace Modules\AuditLog\App\Filament\Resources\AuditLogResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\AuditLog\App\Filament\Resources\AuditLogResource;

class ListAuditLog extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

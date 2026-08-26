<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerCheckinResource\Pages;

use App\Filament\Resources\CustomerCheckinResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerCheckins extends ListRecords
{
    protected static string $resource = CustomerCheckinResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

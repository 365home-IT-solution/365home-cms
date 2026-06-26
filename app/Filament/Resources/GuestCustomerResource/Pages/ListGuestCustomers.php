<?php

declare(strict_types=1);

namespace App\Filament\Resources\GuestCustomerResource\Pages;

use App\Filament\Resources\GuestCustomerResource;
use Filament\Resources\Pages\ListRecords;

class ListGuestCustomers extends ListRecords
{
    protected static string $resource = GuestCustomerResource::class;
}

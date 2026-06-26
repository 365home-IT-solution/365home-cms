<?php

declare(strict_types=1);

namespace App\Filament\Resources\GuestCustomerResource\Pages;

use App\Filament\Resources\GuestCustomerResource;
use Filament\Resources\Pages\ViewRecord;

class ViewGuestCustomer extends ViewRecord
{
    protected static string $resource = GuestCustomerResource::class;
}

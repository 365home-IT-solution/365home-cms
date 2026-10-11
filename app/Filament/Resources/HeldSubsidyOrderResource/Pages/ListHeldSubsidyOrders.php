<?php

declare(strict_types=1);

namespace App\Filament\Resources\HeldSubsidyOrderResource\Pages;

use App\Filament\Resources\HeldSubsidyOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListHeldSubsidyOrders extends ListRecords
{
    protected static string $resource = HeldSubsidyOrderResource::class;
}

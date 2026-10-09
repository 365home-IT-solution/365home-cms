<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerEscrowResource\Pages;

use App\Filament\Resources\PartnerEscrowResource;
use Filament\Resources\Pages\ListRecords;

class ListPartnerEscrows extends ListRecords
{
    protected static string $resource = PartnerEscrowResource::class;
}

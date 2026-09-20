<?php

declare(strict_types=1);

namespace Modules\Invoice\App\Filament\Resources\InvoiceResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Invoice\App\Filament\Resources\InvoiceResource;

class ListInvoice extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        // Không có CreateAction — xem lý do ở InvoiceResource::canCreate().
        return [];
    }
}

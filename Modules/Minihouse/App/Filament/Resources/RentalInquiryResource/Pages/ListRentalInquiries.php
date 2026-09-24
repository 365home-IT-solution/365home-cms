<?php

namespace Modules\Minihouse\App\Filament\Resources\RentalInquiryResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Minihouse\App\Filament\Resources\RentalInquiryResource;

class ListRentalInquiries extends ListRecords
{
    protected static string $resource = RentalInquiryResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }
}

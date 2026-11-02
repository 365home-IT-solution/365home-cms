<?php

namespace Modules\Payment\App\Filament\Resources\ManualPaymentResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Payment\App\Filament\Resources\ManualPaymentResource;

class ListManualPayments extends ListRecords
{
    protected static string $resource = ManualPaymentResource::class;
}

<?php

namespace Modules\Payment\App\Filament\Resources\PaymentConfigurationResource\Pages;

use Modules\Payment\App\Filament\Resources\PaymentConfigurationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPaymentConfiguration extends ListRecords
{
    protected static string $resource = PaymentConfigurationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

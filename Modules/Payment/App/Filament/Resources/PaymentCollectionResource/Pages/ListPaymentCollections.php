<?php

namespace Modules\Payment\App\Filament\Resources\PaymentCollectionResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Payment\App\Filament\Resources\PaymentCollectionResource;

class ListPaymentCollections extends ListRecords
{
    protected static string $resource = PaymentCollectionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tạo mã thu tiền')];
    }
}

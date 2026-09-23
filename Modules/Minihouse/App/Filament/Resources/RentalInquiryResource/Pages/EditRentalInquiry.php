<?php

namespace Modules\Minihouse\App\Filament\Resources\RentalInquiryResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\RentalInquiryResource;

class EditRentalInquiry extends EditRecord
{
    protected static string $resource = RentalInquiryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

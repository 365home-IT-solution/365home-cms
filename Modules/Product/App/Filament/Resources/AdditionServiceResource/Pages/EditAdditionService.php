<?php

namespace Modules\Product\App\Filament\Resources\AdditionServiceResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\Product\App\Filament\Resources\AdditionServiceResource;

class EditAdditionService extends EditRecord
{
    protected static string $resource = AdditionServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

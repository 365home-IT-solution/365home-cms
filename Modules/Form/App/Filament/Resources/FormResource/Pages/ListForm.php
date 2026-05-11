<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\FormResource\Pages;

use Modules\Form\App\Filament\Resources\FormResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListForm extends ListRecords
{
    protected static string $resource = FormResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

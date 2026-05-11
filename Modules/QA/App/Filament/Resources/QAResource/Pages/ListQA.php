<?php

declare(strict_types=1);

namespace Modules\QA\App\Filament\Resources\QAResource\Pages;

use Modules\QA\App\Filament\Resources\QAResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListQA extends ListRecords
{
    protected static string $resource = QAResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

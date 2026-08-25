<?php

declare(strict_types=1);

namespace App\Filament\Resources\ConsultationLogResource\Pages;

use App\Filament\Resources\ConsultationLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListConsultationLogs extends ListRecords
{
    protected static string $resource = ConsultationLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('+ Ghi nhận tư vấn'),
        ];
    }
}

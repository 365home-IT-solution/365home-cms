<?php

declare(strict_types=1);

namespace Modules\SettingCompany\App\Filament\Resources\SettingCompanyResource\Pages;

use Modules\SettingCompany\App\Filament\Resources\SettingCompanyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSettingCompany extends ListRecords
{
    protected static string $resource = SettingCompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

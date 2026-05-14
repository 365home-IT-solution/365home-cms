<?php

declare(strict_types=1);

namespace Modules\SettingCompany\App\Filament\Resources\BranchResource\Pages;

use Modules\SettingCompany\App\Filament\Resources\BranchResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBranch extends ListRecords
{
    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

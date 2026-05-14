<?php

declare(strict_types=1);

namespace Modules\SettingCompany\App\Filament\Resources\BranchResource\Pages;

use Modules\SettingCompany\App\Filament\Resources\BranchResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBranch extends CreateRecord
{
    protected static string $resource = BranchResource::class;
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
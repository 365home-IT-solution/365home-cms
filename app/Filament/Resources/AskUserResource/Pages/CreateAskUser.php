<?php

declare(strict_types=1);

namespace App\Filament\Resources\AskUserResource\Pages;

use App\Filament\Resources\AskUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAskUser extends CreateRecord
{
    protected static string $resource = AskUserResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

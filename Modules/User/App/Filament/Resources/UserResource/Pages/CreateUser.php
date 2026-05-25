<?php

declare(strict_types=1);

namespace Modules\User\App\Filament\Resources\UserResource\Pages;

use Modules\User\App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        $this->record->update([
            'created_by'        => auth()->id(),
            'email_verified_at' => now(),
        ]);
    }
}
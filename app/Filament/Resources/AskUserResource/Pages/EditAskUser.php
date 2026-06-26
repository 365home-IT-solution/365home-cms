<?php

declare(strict_types=1);

namespace App\Filament\Resources\AskUserResource\Pages;

use App\Filament\Resources\AskUserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAskUser extends EditRecord
{
    protected static string $resource = AskUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Xoá'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

<?php

declare(strict_types=1);

namespace Modules\DataPermission\App\Filament\Resources\DataScopeResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\DataPermission\App\Filament\Resources\DataScopeResource;

class EditDataScope extends EditRecord
{
    protected static string $resource = DataScopeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

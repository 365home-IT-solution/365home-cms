<?php

declare(strict_types=1);

namespace Modules\DataPermission\App\Filament\Resources\UserBranchPermissionResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\DataPermission\App\Filament\Resources\UserBranchPermissionResource;

class EditUserBranchPermission extends EditRecord
{
    protected static string $resource = UserBranchPermissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

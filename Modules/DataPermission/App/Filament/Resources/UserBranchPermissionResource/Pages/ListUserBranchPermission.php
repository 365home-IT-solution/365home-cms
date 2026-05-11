<?php

declare(strict_types=1);

namespace Modules\DataPermission\App\Filament\Resources\UserBranchPermissionResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\DataPermission\App\Filament\Resources\UserBranchPermissionResource;

class ListUserBranchPermission extends ListRecords
{
    protected static string $resource = UserBranchPermissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

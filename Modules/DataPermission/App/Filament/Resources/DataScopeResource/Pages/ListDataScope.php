<?php

declare(strict_types=1);

namespace Modules\DataPermission\App\Filament\Resources\DataScopeResource\Pages;

use Modules\DataPermission\App\Filament\Resources\DataScopeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDataScope extends ListRecords
{
    protected static string $resource = DataScopeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Thêm phân quyền'),
        ];
    }
}

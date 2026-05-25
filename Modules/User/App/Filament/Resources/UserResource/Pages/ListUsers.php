<?php

declare(strict_types=1);

namespace Modules\User\App\Filament\Resources\UserResource\Pages;

use Modules\User\App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Illuminate\Database\Eloquent\Builder;
use JoseEspinal\RecordNavigation\Traits\HasRecordsList;

class ListUsers extends ListRecords
{
    use ExposesTableToWidgets;
    use HasRecordsList;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return static::$resource::getWidgets();
    }

    protected function getTableQuery(): Builder
    {
        $user  = auth()->user();
        $query = (new (static::$resource::getModel()))->with('roles')
            ->where('id', '!=', $user->id);

        if ($user->isSuperAdmin()) {
            return $query;
        }

        // Nhân viên thấy toàn bộ cây con: NV2 do NV1 tạo, NV3 do NV2 tạo, ...
        $descendantIds = $user->getAllDescendantIds();

        return $query
            ->whereIn('id', $descendantIds)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', config('filament-shield.super_admin.name')));
    }
}

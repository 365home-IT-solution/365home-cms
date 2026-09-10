<?php

namespace Modules\Minihouse\App\Filament\Resources\RoleResource\Pages;

use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Modules\Minihouse\App\Filament\Resources\RoleResource;

// Xem giải thích ở Pages\CreateRole — cùng cơ chế, chỉ khác thời điểm gọi (sửa thay vì tạo).
class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    public Collection $permissions;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->hidden(fn (Model $record) => in_array($record->name, [config('filament-shield.super_admin.name'), 'Quản lý MiniHouse'])),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->permissions = collect($data)
            ->filter(fn ($permission, $key) => ! in_array($key, ['name', 'guard_name', 'select_all']))
            ->values()
            ->flatten()
            ->push('access_minihouse')
            ->unique();

        return Arr::only($data, ['name', 'guard_name']);
    }

    protected function afterSave(): void
    {
        $permissionModels = collect();

        $this->permissions->each(function ($permission) use ($permissionModels) {
            $permissionModels->push(Utils::getPermissionModel()::firstOrCreate([
                'name'       => $permission,
                'guard_name' => $this->data['guard_name'],
            ]));
        });

        $this->record->syncPermissions($permissionModels);
    }
}

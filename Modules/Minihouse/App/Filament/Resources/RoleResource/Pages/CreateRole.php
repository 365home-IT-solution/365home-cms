<?php

namespace Modules\Minihouse\App\Filament\Resources\RoleResource\Pages;

use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Modules\Minihouse\App\Filament\Resources\RoleResource;

// Cơ chế thu thập/lưu quyền copy NGUYÊN VĂN từ App\Filament\Resources\Shield\RoleResource\Pages\
// CreateRole (Home) — form gửi lên nhiều field CheckboxList rời (mỗi Resource 1 field, cộng
// pages_tab/widgets_tab/custom_permissions), gom hết thành 1 danh sách tên quyền rồi mới
// firstOrCreate() + syncPermissions(). Khác đúng 1 chỗ: LUÔN thêm "access_minihouse" vào danh sách
// dù không có trong dữ liệu form (xem RoleResource::getCustomPermissionOptions() — cố tình ẩn field
// này khỏi tab "Tuỳ chỉnh") — mọi vai trò tạo ở panel MiniHouse phải có quyền vào panel.
class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    public Collection $permissions;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->permissions = collect($data)
            ->filter(fn ($permission, $key) => ! in_array($key, ['name', 'guard_name', 'select_all']))
            ->values()
            ->flatten()
            ->push('access_minihouse')
            ->unique();

        return Arr::only($data, ['name', 'guard_name']);
    }

    protected function afterCreate(): void
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

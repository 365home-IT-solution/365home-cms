<?php

declare(strict_types=1);

namespace Modules\User\App\Filament\Resources\UserResource\Pages;

use App\Models\User;
use Modules\User\App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
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

    // Tabs chỉ hiện với super_admin
    public function getTabs(): array
    {
        if (! auth()->user()?->isSuperAdmin()) {
            return [];
        }

        $currentId = auth()->id();

        $adminCount    = User::where('id', '!=', $currentId)->whereHas('roles')->count();
        $customerCount = User::where('id', '!=', $currentId)->whereDoesntHave('roles')->count();

        return [
            'admins' => Tab::make('Quản trị viên')
                ->icon('heroicon-o-shield-check')
                ->badge($adminCount)
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas('roles')),

            'customers' => Tab::make('Khách hàng')
                ->icon('heroicon-o-users')
                ->badge($customerCount)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDoesntHave('roles')),
        ];
    }

    protected function getTableQuery(): Builder
    {
        $user  = auth()->user();
        $query = (new (static::$resource::getModel()))->with('roles')
            ->where('id', '!=', $user->id);

        if ($user->isSuperAdmin()) {
            return $query;
        }

        // Đối tác/nhân viên chỉ thấy tài khoản thuộc cùng đối tác (partner_id) — thay cho logic
        // cây con created_by cũ, vốn không phản ánh đúng ranh giới đối tác.
        return $query
            ->where('partner_id', $user->partner_id)
            ->whereHas('roles')  // khách hàng không bao giờ hiển thị với non-super-admin
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', config('filament-shield.super_admin.name')));
    }
}

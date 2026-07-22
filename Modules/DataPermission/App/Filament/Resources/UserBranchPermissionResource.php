<?php

declare(strict_types=1);

namespace Modules\DataPermission\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\DataPermission\App\Filament\Resources\UserBranchPermissionResource\Forms\UserBranchPermissionForm;
use Modules\DataPermission\App\Filament\Resources\UserBranchPermissionResource\Pages;
use Modules\DataPermission\App\Filament\Resources\UserBranchPermissionResource\Tables\UserBranchPermissionTable;
use Modules\DataPermission\Entities\UserBranchPermission;

class UserBranchPermissionResource extends Resource
{
    protected static ?string $model = UserBranchPermission::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-building-office-2';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Phân quyền';
    }

    public static function getNavigationLabel(): string
    {
        return 'Phân quyền Chi nhánh';
    }

    public static function getModelLabel(): string
    {
        return 'Phân quyền Chi nhánh';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Phân quyền Chi nhánh';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->distinct('user_id')->count('user_id');
    }

    /**
     * Chỉ lấy 1 dòng/user (dòng cũ nhất), để table hiển thị gọn 1 dòng mỗi user.
     * Non-super_admin chỉ thấy bản ghi của tài khoản thuộc đối tác mình (partner_id), không
     * phải chỉ bản ghi do chính mình tạo — để chủ đối tác thấy được cả phân quyền do người
     * khác trong cùng đối tác đã gán.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = auth()->user();
        if (! $user?->hasRole('super_admin')) {
            $query->whereHas('user', fn (Builder $q) => $q->where('partner_id', $user?->partner_id));
        }

        return $query
            ->selectRaw('MIN(id) as id, user_id, MIN(created_at) as created_at, MIN(updated_at) as updated_at')
            ->groupBy('user_id')
            ->with('user');
    }

    public static function form(Form $form): Form
    {
        return UserBranchPermissionForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return UserBranchPermissionTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUserBranchPermission::route('/'),
            'create' => Pages\CreateUserBranchPermission::route('/create'),
        ];
    }
}

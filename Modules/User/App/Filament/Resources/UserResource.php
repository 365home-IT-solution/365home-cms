<?php

declare(strict_types=1);

namespace Modules\User\App\Filament\Resources;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\DataPermission\Entities\UserBranchPermission;
use Modules\User\App\Filament\Resources\UserResource\Forms\UserForm;
use Modules\User\App\Filament\Resources\UserResource\Tables\UserTable;
use Modules\User\App\Filament\Resources\UserResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Support\Htmlable;
use JulioMotol\FilamentPasswordConfirmation\RequiresPasswordConfirmation;

class UserResource extends Resource
{
    use RequiresPasswordConfirmation;
    
    protected static ?string $model = User::class;

    protected static int $globalSearchResultsLimit = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationIcon(): string
    {
        return __('user::user.resource.navigation_icon');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('user::user.resource.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('user::user.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('user::user.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('user::user.resource.plural_model_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        $allowedBranchIds = $user->allowedBranchIds();

        if (empty($allowedBranchIds)) {
            return $query->whereRaw('1 = 0');
        }

        // Chỉ hiển thị users đã được phân quyền vào các chi nhánh được phép
        $userIds = UserBranchPermission::whereIn('category_id', $allowedBranchIds)
            ->distinct()
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('id', $userIds);
    }

    public static function form(Form $form): Form
    {
        return UserForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return UserTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return $record->email;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['email', 'fullname',];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'name' => $record->fullname,
        ];
    }

}

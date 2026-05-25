<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Filament\Resources;

use Modules\Coupon\App\Filament\Resources\CouponResource\Forms\CouponForm;
use Modules\Coupon\App\Filament\Resources\CouponResource\Tables\CouponTable;
use Modules\Coupon\App\Filament\Resources\CouponResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\DataPermission\Entities\UserBranchPermission;
use Modules\Product\App\Models\Product;
use Modules\Promotion\App\Models\Coupon;

class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';
    protected static ?string $navigationGroup = 'Quản lý';

    public static function getNavigationLabel(): string
    {
        return 'Mã giảm giá';
    }

    public static function getModelLabel(): string
    {
        return 'Mã giảm giá';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Mã giảm giá';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_coupon') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if ($user?->hasRole('super_admin')) {
            return $query;
        }

        $branchIds = UserBranchPermission::where('user_id', $user?->id)->pluck('category_id');

        // Room IDs thuộc các chi nhánh được phân quyền
        $permittedRoomIds = Product::whereHas(
            'categories',
            fn ($q) => $q->whereIn('categories.id', $branchIds)
        )->pluck('id');

        // User IDs có ít nhất 1 chi nhánh trùng với người xem
        $overlappingUserIds = UserBranchPermission::whereIn('category_id', $branchIds)
            ->pluck('user_id');

        return $query->where(function (Builder $q) use ($permittedRoomIds, $overlappingUserIds) {
            // specific_room / specific_slot: lọc theo room thuộc chi nhánh
            $q->whereIn('room_id', $permittedRoomIds)
                // all_rooms: chỉ hiển thị nếu người tạo không có hoặc có chi nhánh trùng
                ->orWhere(function (Builder $q2) use ($overlappingUserIds) {
                    $q2->where('apply_type', 'all_rooms')
                        ->where(function (Builder $q3) use ($overlappingUserIds) {
                            $q3->whereNull('created_by')
                                ->orWhereIn('created_by', $overlappingUserIds);
                        });
                });
        });
    }

    public static function form(Form $form): Form
    {
        return CouponForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return CouponTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCoupon::route('/'),
            'create' => Pages\CreateCoupon::route('/create'),
            'edit' => Pages\EditCoupon::route('/{record}/edit'),
        ];
    }
}

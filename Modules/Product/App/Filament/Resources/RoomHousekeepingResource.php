<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Modules\Product\App\Filament\Resources\RoomHousekeepingResource\Pages;
use Modules\Product\App\Models\Product;

// Màn hình RIÊNG để cả nhân viên lẫn chủ đối tác cùng theo dõi/kiểm tra tình trạng dọn vệ sinh
// từng phòng — tách khỏi ProductResource ("Thiết lập Phòng", nhiều field không liên quan) để
// không phải đào bới. Chỉ xem + 2 action dọn phòng (đã có sẵn từ ProductAction/RoomCleaningAction).
class RoomHousekeepingResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Quản lý API';

    protected static ?string $navigationLabel = 'Kiểm tra dọn phòng';

    // BẮT BUỘC khai báo riêng — nếu không, Filament tự suy nhãn từ tên MODEL dùng chung
    // (Product::class, giống hệt ProductResource/RoomAmenityAssignResource) thành "Product", khiến
    // trang phân quyền (Shield) hiện nhiều mục cùng tên "Product" không phân biệt được — đây chính
    // là lý do "Kiểm tra dọn phòng" tưởng như biến mất khỏi trang chọn quyền dù thực ra vẫn ở đó,
    // chỉ bị đặt nhầm tên trùng với mục khác.
    protected static ?string $modelLabel       = 'Kiểm tra dọn phòng';
    protected static ?string $pluralModelLabel = 'Kiểm tra dọn phòng';

    protected static ?string $slug = 'room-housekeeping';

    protected static ?int $navigationSort = 6;

    // Nhân viên KHÔNG được gán "làm việc tất cả chi nhánh" chỉ thấy phòng thuộc đúng chi nhánh
    // mình phụ trách — chủ đối tác/super_admin thấy toàn bộ phòng của đối tác (partner_id đã
    // được BelongsToPartner trên Product tự lọc, không cần lặp lại ở đây).
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin() || $user->isPartnerOwner()) {
            return $query;
        }

        $employee = $user->employee;

        if ($employee && ! $employee->works_all_branches) {
            $branchIds = $employee->workBranches()->pluck('categories.id');

            $query->whereHas('categories', function (Builder $q) use ($branchIds) {
                $q->where('category_type', 'product')->whereIn('categories.id', $branchIds);
            });
        }

        return $query;
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->where('housekeeping_status', 'cleaning')->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoomHousekeeping::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_room::housekeeping') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}

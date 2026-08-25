<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Modules\Product\App\Filament\Resources\RoomHousekeepingResource\Pages;
use Modules\Product\App\Models\Product;

// Màn hình RIÊNG để theo dõi/kiểm tra tình trạng dọn vệ sinh từng phòng — tách khỏi ProductResource
// ("Thiết lập Phòng", nhiều field không liên quan) để không phải đào bới. Chỉ xem + 2 action dọn
// phòng (đã có sẵn từ ProductAction/RoomCleaningAction).
//
// canViewAny() dựa theo permission Shield (view_any_room::housekeeping) — model dùng chung Product
// với ProductResource nên default Filament::canViewAny() (Gate qua ProductPolicy) sẽ luôn resolve
// nhầm về permission 'view_any_product', vì vậy vẫn phải override thủ công, nhưng bằng permission
// check thay vì hard-code isSuperAdmin() như trước. super_admin luôn qua (không được seed permission
// này — bypass thẳng bằng isSuperAdmin(), theo đúng quy ước isSuperAdmin() dùng khắp codebase).
// Role 'partner'/'employee' được seed quyền này qua PartnerRolePermissionsSeeder; đối tác có thể tự
// bật/tắt cho role phụ tự tạo của mình ở trang Roles & Permissions.
class RoomHousekeepingResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Quản lý';

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
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->can('view_any_room::housekeeping'));
    }

    // Thu hẹp danh sách phòng hiển thị theo chi nhánh được phép xem — giống hệt cách
    // ProductResource::getEloquentQuery() làm (allowedCategoryIds()), để nhất quán với các resource
    // khác trong hệ thống. Product đã tự lọc theo partner_id qua BelongsToPartner nên chỉ cần thu
    // hẹp thêm theo chi nhánh nếu user bị giới hạn cụ thể. Nút thao tác dọn phòng trên từng dòng vẫn
    // được RoomCleaningAction::canManageCleaning() gác thêm 1 lớp riêng (theo chi nhánh TRỰC của
    // nhân viên qua Employee::work_branch_ids — có thể hẹp hơn allowedCategoryIds()).
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        $allCategoryIds = $user->allowedCategoryIds();

        if (empty($allCategoryIds)) {
            return $query;
        }

        return $query->whereHas('categories', function (Builder $q) use ($allCategoryIds) {
            $q->whereIn('categories.id', $allCategoryIds);
        });
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

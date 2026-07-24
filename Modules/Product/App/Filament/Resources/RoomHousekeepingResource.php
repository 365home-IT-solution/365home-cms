<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources;

use Filament\Resources\Resource;
use Modules\Product\App\Filament\Resources\RoomHousekeepingResource\Pages;
use Modules\Product\App\Models\Product;

// Màn hình RIÊNG để theo dõi/kiểm tra tình trạng dọn vệ sinh từng phòng — tách khỏi ProductResource
// ("Thiết lập Phòng", nhiều field không liên quan) để không phải đào bới. Chỉ xem + 2 action dọn
// phòng (đã có sẵn từ ProductAction/RoomCleaningAction).
//
// CHỈ super_admin được xem trang này — canViewAny() hard-code isSuperAdmin(), KHÔNG dựa vào
// permission Shield (*_room::housekeeping), nên tick/bỏ tick quyền này ở Roles & Permissions cho
// role khác không có tác dụng (giống quy ước ở RoomImageResource cho room::image). Trước đây cấp
// quyền này cho role 'partner'/'employee' qua PartnerRolePermissionsSeeder — đã gỡ khỏi seeder đó.
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
        return auth()->user()?->isSuperAdmin() ?? false;
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

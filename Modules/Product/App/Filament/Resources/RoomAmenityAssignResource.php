<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources;

use Filament\Resources\Resource;
use Modules\Product\App\Filament\Resources\RoomAmenityAssignResource\Pages;
use Modules\Product\App\Models\Product;

class RoomAmenityAssignResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Quản lý API';

    protected static ?string $navigationLabel = 'Gán Tiện Ích Phòng';

    // Gộp vào mục "Thông tin & Cấu hình Phòng" đã bỏ khỏi menu — ẩn tạm, giữ nguyên route/API.
    // Bật lại bằng cách xoá method này.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    // BẮT BUỘC khai báo riêng — dùng chung model Product với ProductResource, không khai báo thì
    // Filament tự suy nhãn trang phân quyền (Shield) từ tên model thành "Product", trùng tên với
    // mục khác, không phân biệt được trên trang chọn quyền.
    protected static ?string $modelLabel       = 'Gán Tiện Ích Phòng';
    protected static ?string $pluralModelLabel = 'Gán Tiện Ích Phòng';

    protected static ?string $slug = 'room-amenity-assign';

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return 'Gán Tiện Ích Phòng';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Gán Tiện Ích Phòng';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::where('is_activated', true)->count();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoomAmenityAssign::route('/'),
            'edit'  => Pages\EditRoomAmenityAssign::route('/{record}/edit'),
        ];
    }

    // Theo yêu cầu: CHỈ super_admin được dùng "Gán Tiện Ích Phòng" (nhóm Quản lý API) — hard-code
    // isSuperAdmin(), không dựa vào permission Shield (room::amenity::assign) nữa, giống
    // PartnerResource/BranchResource — tránh bị cấp nhầm quyền này cho role khác qua Roles &
    // Permissions.
    private static function isSuperAdmin(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canViewAny(): bool
    {
        return self::isSuperAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return self::isSuperAdmin();
    }

    public static function canDelete($record): bool
    {
        return self::isSuperAdmin();
    }

    public static function canDeleteAny(): bool
    {
        return self::isSuperAdmin();
    }
}

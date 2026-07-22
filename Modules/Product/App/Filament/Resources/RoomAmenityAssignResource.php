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

    // Trước đây hardcode isSuperAdmin() ở cả 4 hàm — bỏ qua các permission room::amenity::assign
    // (đã có sẵn), khiến tick/bỏ tick quyền này ở Roles & Permissions vô tác dụng.
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_room::amenity::assign') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('update_room::amenity::assign') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->can('delete_room::amenity::assign') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->can('delete_any_room::amenity::assign') ?? false;
    }
}

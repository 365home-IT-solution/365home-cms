<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Product\App\Filament\Resources\RoomImageResource\Forms\RoomImageForm;
use Modules\Product\App\Filament\Resources\RoomImageResource\Pages;
use Modules\Product\App\Filament\Resources\RoomImageResource\Tables\RoomImageTable;
use Modules\Product\App\Models\RoomImage;

class RoomImageResource extends Resource
{
    protected static ?string $model = RoomImage::class;

    // Trước đây isSuperAdmin() hardcode ở cả 5 hàm — bỏ qua RoomImagePolicy (đã đúng, kiểm tra
    // *_room::image), khiến tick/bỏ tick quyền này ở Roles & Permissions vô tác dụng.
    public static function canViewAny(): bool { return auth()->user()?->can('view_any_room::image') ?? false; }
    public static function canCreate(): bool { return auth()->user()?->can('create_room::image') ?? false; }
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool { return auth()->user()?->can('update_room::image') ?? false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return auth()->user()?->can('delete_room::image') ?? false; }
    public static function canDeleteAny(): bool { return auth()->user()?->can('delete_any_room::image') ?? false; }

    // Gộp vào mục "Thông tin & Cấu hình Phòng" đã bỏ khỏi menu — ẩn tạm, giữ nguyên route/API.
    // Bật lại bằng cách xoá method này.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-photo';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý API';
    }

    public static function getNavigationLabel(): string
    {
        return 'Ảnh Phòng';
    }

    public static function getModelLabel(): string
    {
        return 'Ảnh phòng';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Ảnh Phòng';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return RoomImageForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return RoomImageTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRoomImage::route('/'),
            'create' => Pages\CreateRoomImage::route('/create'),
            'edit'   => Pages\EditRoomImage::route('/{record}/edit'),
        ];
    }
}

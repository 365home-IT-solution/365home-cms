<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Product\App\Filament\Resources\RoomSpecialResource\Forms\RoomSpecialForm;
use Modules\Product\App\Filament\Resources\RoomSpecialResource\Pages;
use Modules\Product\App\Filament\Resources\RoomSpecialResource\Tables\RoomSpecialTable;
use Modules\Product\App\Models\RoomSpecial;

class RoomSpecialResource extends Resource
{
    protected static ?string $model = RoomSpecial::class;

    // RoomSpecial không có cột partner_id riêng — lọc qua partner_id của Product cha.
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        return $query->whereHas('product', fn (Builder $q) => $q->where('partner_id', $user->partner_id));
    }

    // Gộp vào mục "Thông tin & Cấu hình Phòng" đã bỏ khỏi menu — ẩn tạm, giữ nguyên route/API.
    // Bật lại bằng cách xoá method này.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-star';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý API';
    }

    public static function getNavigationLabel(): string
    {
        return 'Điểm Đặc Biệt';
    }

    public static function getModelLabel(): string
    {
        return 'Điểm đặc biệt';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Điểm Đặc Biệt';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    // Trước đây hardcode isSuperAdmin() — bỏ qua RoomSpecialPolicy (đã đúng, kiểm tra
    // view_any_room::special), khiến tick/bỏ tick quyền này ở Roles & Permissions vô tác dụng.
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_room::special') ?? false;
    }

    public static function form(Form $form): Form
    {
        return RoomSpecialForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return RoomSpecialTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRoomSpecial::route('/'),
            'create' => Pages\CreateRoomSpecial::route('/create'),
            'edit'   => Pages\EditRoomSpecial::route('/{record}/edit'),
        ];
    }
}

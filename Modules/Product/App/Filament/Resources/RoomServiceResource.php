<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Product\App\Filament\Resources\RoomServiceResource\Forms\RoomServiceForm;
use Modules\Product\App\Filament\Resources\RoomServiceResource\Pages;
use Modules\Product\App\Filament\Resources\RoomServiceResource\Tables\RoomServiceTable;
use Modules\Product\App\Models\RoomService;

class RoomServiceResource extends Resource
{
    protected static ?string $model = RoomService::class;

    // RoomService không có cột partner_id riêng (đã gắn trực tiếp product_id) — lọc qua
    // partner_id của Product cha (Product tự lọc theo BelongsToPartner rồi).
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
        return 'heroicon-o-shopping-bag';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý API';
    }

    public static function getNavigationLabel(): string
    {
        return 'Dịch vụ Phòng';
    }

    public static function getModelLabel(): string
    {
        return 'Dịch vụ';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Dịch vụ Phòng';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    // Trước đây hardcode isSuperAdmin() — bỏ qua RoomServicePolicy (đã đúng, kiểm tra
    // view_any_room::service), khiến tick/bỏ tick quyền này ở Roles & Permissions vô tác dụng.
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_room::service') ?? false;
    }

    public static function form(Form $form): Form
    {
        return RoomServiceForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return RoomServiceTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRoomService::route('/'),
            'create' => Pages\CreateRoomService::route('/create'),
            'edit'   => Pages\EditRoomService::route('/{record}/edit'),
        ];
    }
}

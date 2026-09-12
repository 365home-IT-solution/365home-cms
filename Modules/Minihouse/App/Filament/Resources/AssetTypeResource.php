<?php

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\AssetTypeResource\Forms\AssetTypeForm;
use Modules\Minihouse\App\Filament\Resources\AssetTypeResource\Pages;
use Modules\Minihouse\App\Filament\Resources\AssetTypeResource\Tables\AssetTypeTable;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Models\AssetType;

// Danh mục "Loại tài sản" (tủ lạnh, máy lạnh, giường, tủ...) — CRUD riêng để nhân viên tạo/sửa/xoá 1
// lần, sau đó chọn lại ở Repeater "Tài sản trong phòng" (RoomForm) thay vì gõ tay tự do mỗi phòng.
// Yêu cầu người dùng 2026-09-11: "nếu nhiều phòng thì nhập như vậy rất lâu, tôi muốn ở phòng chỉ có
// thể chọn thôi" — mirror đúng kiến trúc AmenityResource đã có (cùng permissionGroup 'rooms', cùng vị
// trí điều hướng ngay sau Phòng/Tiện ích).
class AssetTypeResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = AssetType::class;
    protected static ?string $navigationIcon  = 'heroicon-o-cube';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Loại tài sản';
    // Đứng ngay sau "Tiện ích" (sort=3) — cùng là danh mục phụ trợ cho phòng.
    protected static ?int $navigationSort = 4;

    public static function getModelLabel(): string
    {
        return 'Loại tài sản';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Loại tài sản';
    }

    public static function permissionGroup(): string
    {
        // Dùng chung quyền với "Phòng" — cùng nguyên tắc AmenityResource (danh mục phụ trợ, không
        // cần bộ quyền riêng).
        return 'rooms';
    }

    public static function form(Form $form): Form
    {
        return AssetTypeForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return AssetTypeTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAssetTypes::route('/'),
            'create' => Pages\CreateAssetType::route('/create'),
            'edit'   => Pages\EditAssetType::route('/{record}/edit'),
        ];
    }
}
